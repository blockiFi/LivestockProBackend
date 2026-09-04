<?php

namespace App\Services\FlockRecordImport;

use App\Models\Farm;
use App\Models\FlockRecordImport;
use App\Models\FlockRecordImportItem;
use App\Models\PoultryFeedType;
use App\Services\LlmService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FlockRecordImportAiService
{
    public function __construct(
        private readonly LlmService $llm,
        private readonly FlockRecordImportParser $parser,
        private readonly SpreadsheetKit $kit,
    ) {
    }

    /**
     * @return array{ai_available: bool, warnings: list<string>}
     */
    public function extractAndPopulate(FlockRecordImport $draft, Farm $farm): array
    {
        $warnings = [];
        $disk = Storage::disk('public');

        if (! $draft->source_path || ! $disk->exists($draft->source_path)) {
            return ['ai_available' => false, 'warnings' => ['Uploaded file not found in storage']];
        }

        $absolute = $disk->path($draft->source_path);
        $raw = null;

        if (in_array($draft->source_type, ['pdf', 'image'], true)) {
            $vision = $this->buildVisionInputs($draft, $absolute, $warnings);
            if ($vision === []) {
                return ['ai_available' => false, 'warnings' => $warnings ?: ['Unable to build vision input']];
            }
            $raw = $this->llm->visionChatMany($this->systemPrompt($farm), $this->userPrompt(), $vision);
        } else {
            // Spreadsheet: send a textual excerpt to the LLM
            try {
                $sheets = $this->kit->readFile($absolute, $draft->source_type);
                $excerpt = $this->sheetsToText($sheets);
                $raw = $this->llm->chat(
                    $this->systemPrompt($farm),
                    $this->userPrompt()."\n\nSpreadsheet excerpt:\n".$excerpt
                );
            } catch (\Throwable $e) {
                return ['ai_available' => false, 'warnings' => ['Failed to read spreadsheet: '.$e->getMessage()]];
            }
        }

        if (! $raw) {
            $detail = $this->llm->getLastError();
            $msg = 'LLM unavailable or returned empty response'.($detail ? (': '.$detail) : '');

            return ['ai_available' => false, 'warnings' => array_merge($warnings, [$msg])];
        }

        $json = $this->safeJsonDecode($raw);
        if (! $json) {
            $draft->update([
                'llm_provider' => config('llm.provider'),
                'llm_model' => config('llm.openai.model'),
                'llm_raw_response' => $raw,
            ]);

            return ['ai_available' => true, 'warnings' => array_merge($warnings, ['LLM response was not valid JSON'])];
        }

        $records = $json['records'] ?? $json;
        if (! is_array($records)) {
            return ['ai_available' => true, 'warnings' => array_merge($warnings, ['LLM JSON missing records array'])];
        }

        // Flatten if keyed by type
        if ($this->looksKeyedByType($records)) {
            $flat = [];
            foreach ($records as $type => $rows) {
                if (! is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $row['record_type'] = $row['record_type'] ?? $type;
                    $flat[] = $row;
                }
            }
            $records = $flat;
        }

        $items = $this->parser->normalizeAiItems($records, $farm);
        $items = $this->parser->applyOverlapRules($items);

        if (count($items) > FlockRecordImportSchema::MAX_ITEMS) {
            $warnings[] = 'Truncated to '.FlockRecordImportSchema::MAX_ITEMS.' rows.';
            $items = array_slice($items, 0, FlockRecordImportSchema::MAX_ITEMS);
        }

        DB::transaction(function () use ($draft, $raw, $items) {
            $draft->items()->delete();
            $draft->update([
                'llm_provider' => config('llm.provider'),
                'llm_model' => config('llm.openai.model'),
                'llm_raw_response' => $raw,
                'status' => FlockRecordImport::STATUS_DRAFT,
            ]);
            foreach ($items as $item) {
                $draft->items()->create($item);
            }
        });

        return ['ai_available' => true, 'warnings' => $warnings];
    }

    private function systemPrompt(Farm $farm): string
    {
        $feedTypes = PoultryFeedType::query()
            ->where(fn ($q) => $q->where('farm_id', $farm->id)->orWhereNull('farm_id'))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name')
            ->all();

        $feedHint = $feedTypes
            ? 'Available feed type names (prefer exact match): '.implode(', ', $feedTypes)
            : 'No farm feed types found; leave poultry_feed_type null if unknown.';

        $types = implode(', ', FlockRecordImportItem::RECORD_TYPES);

        return 'You extract poultry flock operational records from documents or spreadsheets. '
            .'Return ONLY valid JSON. Schema: {"records":[{'
            .'"record_type":"daily|mortality|eggs|feed_usage|expenditure|flock_sale|product_sale",'
            .'"date":"YYYY-MM-DD",'
            .'"mortality_count":number|null,"culling_count":number|null,'
            .'"feed_consumption_kg":number|null,"poultry_feed_type":string|null,'
            .'"water_consumption_liters":number|null,"eggs_collected":number|null,"eggs_broken":number|null,'
            .'"average_weight":number|null,"average_weight_kg":number|null,"average_egg_weight":number|null,'
            .'"quantity":number|null,"unit_cost":number|null,'
            .'"category":string|null,"amount":number|null,"currency":string|null,"description":string|null,'
            .'"payment_method":string|null,"reference_no":string|null,'
            .'"unit_price":number|null,"customer_name":string|null,"customer_phone":string|null,'
            .'"type":"egg|meat|manure|null","payment_status":string|null,'
            .'"notes":string|null,"confidence":number|null'
            .'}]}. '
            ."record_type must be one of: {$types}. "
            .'For product_sale, type is egg|meat|manure. '
            .'For flock_sale, quantity is bird count. '
            .'Skip empty rows. '.$feedHint;
    }

    private function userPrompt(): string
    {
        return 'Extract all flock operational records from this document. Output JSON only.';
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sheets
     */
    private function sheetsToText(array $sheets): string
    {
        $chunks = [];
        $rowBudget = 80;
        foreach ($sheets as $name => $rows) {
            $chunks[] = "### Sheet: {$name}";
            foreach (array_slice($rows, 0, 25) as $row) {
                if ($rowBudget-- <= 0) {
                    break 2;
                }
                $chunks[] = json_encode($row, JSON_UNESCAPED_UNICODE);
            }
        }

        return implode("\n", $chunks);
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array{mime:string,base64:string}>
     */
    private function buildVisionInputs(FlockRecordImport $draft, string $absolute, array &$warnings): array
    {
        if ($draft->source_type === 'image') {
            $mime = match (strtolower(pathinfo($absolute, PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => 'image/png',
            };
            $bytes = @file_get_contents($absolute);
            if ($bytes === false) {
                $warnings[] = 'Could not read image file';

                return [];
            }

            return [['mime' => $mime, 'base64' => base64_encode($bytes)]];
        }

        // PDF → images via Imagick when available
        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) {
            $warnings[] = 'PDF import requires Imagick. Upload an image or spreadsheet instead.';

            return [];
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage($absolute);
            $maxPages = (int) config('llm.schedule_import.pdf_max_pages', 6);
            $images = [];
            $page = 0;
            foreach ($imagick as $frame) {
                if ($page >= $maxPages) {
                    break;
                }
                $frame->setImageFormat('png');
                $images[] = [
                    'mime' => 'image/png',
                    'base64' => base64_encode($frame->getImageBlob()),
                ];
                $page++;
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

    private function looksKeyedByType(array $records): bool
    {
        $keys = array_keys($records);
        if ($keys === [] || array_is_list($records)) {
            return false;
        }
        foreach ($keys as $key) {
            if (in_array($key, FlockRecordImportItem::RECORD_TYPES, true)) {
                return true;
            }
        }

        return false;
    }
}
