<?php

namespace App\Services;

use App\Services\FlockRecordImport\SpreadsheetKit;

/**
 * Extracts individual bird weights from a weighing sheet photo, PDF or spreadsheet.
 * Results are a draft only; nothing is persisted here.
 */
class WeightSampleExtractionService
{
    public const MAX_SAMPLES = 2000;

    public function __construct(
        private readonly LlmService $llm,
        private readonly SpreadsheetKit $kit,
    ) {
    }

    /**
     * @return array{
     *     ok: bool, source_type: string, unit: ?string,
     *     samples: list<array{weight_g: float, sex: ?string}>,
     *     target_weight_g: ?float, date: ?string, house: ?string,
     *     confidence: ?float, warnings: list<string>
     * }
     */
    public function extract(string $absolutePath, string $extension): array
    {
        $extension = strtolower($extension);
        $sourceType = match ($extension) {
            'pdf' => 'pdf',
            'csv', 'xlsx' => 'spreadsheet',
            default => 'image',
        };

        $warnings = [];
        $raw = null;

        if ($sourceType === 'spreadsheet') {
            try {
                $excerpt = $this->spreadsheetExcerpt($absolutePath, $extension);
            } catch (\Throwable $e) {
                return $this->failure($sourceType, ['Failed to read spreadsheet: '.$e->getMessage()]);
            }

            $raw = $this->llm->chat(
                $this->systemPrompt(),
                $this->userPrompt()."\n\nSpreadsheet contents:\n".$excerpt
            );

            if (! $raw) {
                // Deterministic fallback: a plain column of numbers still works without the LLM.
                $fallback = $this->numericFallback($excerpt);
                if ($fallback !== []) {
                    return $this->result($sourceType, [
                        'unit' => $this->guessUnit($fallback),
                        'samples' => array_map(fn ($w) => ['weight' => $w, 'sex' => null], $fallback),
                    ], ['AI unavailable; weights were read directly from the file. Please verify the unit.']);
                }
            }
        } else {
            $images = $this->visionInputs($absolutePath, $extension, $warnings);
            if ($images === []) {
                return $this->failure($sourceType, $warnings ?: ['Unable to read the uploaded file']);
            }
            $raw = $this->llm->visionChatMany($this->systemPrompt(), $this->userPrompt(), $images, ['json' => true]);
        }

        if (! $raw) {
            $detail = $this->llm->getLastError();

            return $this->failure($sourceType, array_merge(
                $warnings,
                ['AI extraction unavailable'.($detail ? ': '.$detail : '')]
            ));
        }

        $json = $this->safeJsonDecode($raw);
        if (! $json) {
            return $this->failure($sourceType, array_merge($warnings, ['AI response was not valid JSON']));
        }

        return $this->result($sourceType, $json, $warnings);
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  list<string>  $warnings
     */
    private function result(string $sourceType, array $json, array $warnings): array
    {
        $rows = $json['samples'] ?? $json['weights'] ?? [];
        if (! is_array($rows)) {
            $rows = [];
        }

        $values = [];
        $sexes = [];
        foreach ($rows as $row) {
            $weight = is_array($row) ? ($row['weight'] ?? $row['weight_g'] ?? null) : $row;
            if (! is_numeric($weight) || (float) $weight <= 0) {
                continue;
            }
            $values[] = (float) $weight;
            $sex = is_array($row) ? strtolower((string) ($row['sex'] ?? '')) : '';
            $sexes[] = in_array($sex, ['male', 'female'], true) ? $sex : null;
        }

        $unit = strtolower((string) ($json['unit'] ?? '')) ?: null;
        if (! in_array($unit, ['g', 'kg'], true)) {
            $unit = $this->guessUnit($values);
            if ($values !== []) {
                $warnings[] = "Weight unit not stated; assumed {$unit}. Please verify.";
            }
        }
        $factor = $unit === 'kg' ? 1000 : 1;

        if (count($values) > self::MAX_SAMPLES) {
            $warnings[] = 'Truncated to '.self::MAX_SAMPLES.' samples.';
            $values = array_slice($values, 0, self::MAX_SAMPLES);
            $sexes = array_slice($sexes, 0, self::MAX_SAMPLES);
        }

        $samples = [];
        foreach ($values as $i => $value) {
            $samples[] = ['weight_g' => round($value * $factor, 1), 'sex' => $sexes[$i] ?? null];
        }

        if ($samples === []) {
            $warnings[] = 'No bird weights were found in the file.';
        }

        $target = $json['target_weight'] ?? null;
        $targetG = is_numeric($target) && (float) $target > 0 ? round((float) $target * $factor, 1) : null;

        $date = $json['date'] ?? null;
        $date = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;

        foreach ((array) ($json['warnings'] ?? []) as $w) {
            if (is_string($w) && $w !== '') {
                $warnings[] = $w;
            }
        }

        return [
            'ok' => $samples !== [],
            'source_type' => $sourceType,
            'unit' => $unit,
            'samples' => $samples,
            'target_weight_g' => $targetG,
            'date' => $date,
            'house' => is_string($json['house'] ?? null) ? $json['house'] : null,
            'confidence' => is_numeric($json['confidence'] ?? null) ? (float) $json['confidence'] : null,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function failure(string $sourceType, array $warnings): array
    {
        return [
            'ok' => false,
            'source_type' => $sourceType,
            'unit' => null,
            'samples' => [],
            'target_weight_g' => null,
            'date' => null,
            'house' => null,
            'confidence' => null,
            'warnings' => $warnings,
        ];
    }

    /**
     * Poultry live weights above ~20 are grams; small decimals are kilograms.
     *
     * @param  list<float>  $values
     */
    private function guessUnit(array $values): string
    {
        if ($values === []) {
            return 'g';
        }
        sort($values);
        $median = $values[intdiv(count($values), 2)];

        return $median < 20 ? 'kg' : 'g';
    }

    private function systemPrompt(): string
    {
        return 'You read poultry flock weighing sheets (handwritten or printed tallies, scale printouts, spreadsheets). '
            .'Extract every individual bird weight. Return ONLY valid JSON with this schema: '
            .'{"unit":"g|kg","samples":[{"weight":number,"sex":"male|female|null"}],'
            .'"target_weight":number|null,"date":"YYYY-MM-DD"|null,"house":string|null,'
            .'"confidence":number,"warnings":[string]}. '
            .'Rules: one entry per bird in the order they appear; if a weight is written with a tally count '
            .'(e.g. "1.8 x 3" or a histogram row), repeat it that many times; ignore totals, averages and headers; '
            .'use the unit written on the sheet, otherwise infer it (values under 20 are kg); '
            .'target_weight is the standard/target/expected weight if shown, in the same unit; '
            .'confidence is 0-1; add a warning for any illegible or ambiguous values.';
    }

    private function userPrompt(): string
    {
        return 'Extract all individual bird weights from this weighing record. Output JSON only.';
    }

    private function spreadsheetExcerpt(string $absolutePath, string $extension): string
    {
        if ($extension === 'csv') {
            $lines = file($absolutePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            return implode("\n", array_slice($lines, 0, 2500));
        }

        $sheets = $this->kit->readFile($absolutePath, $extension);
        $chunks = [];
        $budget = 2500;
        foreach ($sheets as $name => $rows) {
            $chunks[] = "### Sheet: {$name}";
            if ($rows !== []) {
                $chunks[] = implode(',', array_keys($rows[0]));
            }
            foreach ($rows as $row) {
                if ($budget-- <= 0) {
                    break 2;
                }
                $chunks[] = implode(',', array_map(fn ($v) => (string) $v, $row));
            }
        }

        return implode("\n", $chunks);
    }

    /**
     * @return list<float>
     */
    private function numericFallback(string $text): array
    {
        preg_match_all('/(?<![\w.])\d+(?:\.\d+)?(?![\w.])/', $text, $m);

        return array_values(array_filter(
            array_map('floatval', $m[0] ?? []),
            fn (float $v) => $v > 0
        ));
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array{mime: string, base64: string}>
     */
    private function visionInputs(string $absolutePath, string $extension, array &$warnings): array
    {
        if ($extension !== 'pdf') {
            $mime = match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => 'image/png',
            };
            $bytes = @file_get_contents($absolutePath);
            if ($bytes === false) {
                $warnings[] = 'Could not read image file';

                return [];
            }

            return [['mime' => $mime, 'base64' => base64_encode($bytes)]];
        }

        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) {
            $warnings[] = 'PDF extraction requires Imagick on the server. Upload a photo or spreadsheet instead.';

            return [];
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage($absolutePath);
            $images = [];
            foreach ($imagick as $i => $frame) {
                if ($i >= 6) {
                    break;
                }
                $frame->setImageFormat('png');
                $images[] = ['mime' => 'image/png', 'base64' => base64_encode($frame->getImageBlob())];
            }
            $imagick->clear();
            $imagick->destroy();

            return $images;
        } catch (\Throwable $e) {
            $warnings[] = 'PDF render failed: '.$e->getMessage();

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeJsonDecode(string $raw): ?array
    {
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
