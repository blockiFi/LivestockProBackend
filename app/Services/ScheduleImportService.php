<?php

namespace App\Services;

use App\Models\PoultryFeedType;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ScheduleImportService
{
    public function __construct(protected LlmService $llm)
    {
    }

    /**
     * Extract schedule items from the stored document and populate draft items.
     *
     * Returns: ['ai_available'=>bool, 'warnings'=>string[]]
     */
    public function extractAndPopulate(ScheduleImport $draft, int $poultryTypeId): array
    {
        $warnings = [];

        $disk = Storage::disk('public');
        if (!$disk->exists($draft->source_path)) {
            return ['ai_available' => false, 'warnings' => ['Uploaded file not found in storage']];
        }

        // Try to get image payload(s) for the LLM (vision).
        $vision = $this->buildVisionInputs($draft, $warnings);
        if (!$vision || empty($vision['images'])) {
            return ['ai_available' => false, 'warnings' => $warnings ?: ['Unable to build vision input']];
        }

        // Provide the model with available feed types so it can pick a concrete feed_type_name.
        $feedTypeNames = PoultryFeedType::where('poultry_type_id', $poultryTypeId)
            ->where(function ($q) use ($draft) {
                $q->where('farm_id', $draft->farm_id)->orWhereNull('farm_id');
            })
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $feedTypeHint = $feedTypeNames
            ? ("Available feed types (choose EXACT name):\n- " . implode("\n- ", $feedTypeNames))
            : "No feed types are available in the database for this poultry type. Use feed_type_name=null.";

        $system = 'You are an expert poultry farm schedule planner. '
            . 'Extract vaccination, medication and feeding schedules from the provided document. '
            . 'Return ONLY valid JSON and nothing else. '
            . 'CRITICAL: First detect how the feeding section is structured in the document and set feeding_layout: '
            . '"range" when the document groups feed by week, age span, or day ranges (e.g. "Week 1", "Days 1-7", "Day 14 onwards", same feed/rate across consecutive days); '
            . '"per_day" when the document is a daily table with one row per flock day (Day 1, Day 2, Day 3…) and values may differ per day. '
            . 'Set feeding_layout_reason to a short explanation of why you chose that layout. '
            . 'For feedings, ALWAYS provide feeding_times (at least 2 time slots if the document does not specify), '
            . 'and ALWAYS provide a human-friendly name and description (you may infer sensible defaults). '
            . 'feeding_times percentages should sum to 100 for each feeding row. '
            . 'When feeding_layout is "range": output one feeding object per span (start_day/end_day). '
            . 'Examples: "Week 1" or "days 1-7" → start_day=1, end_day=7; "Day 14 onwards" → start_day=14, end_day=null; '
            . 'a single day in a range-style doc → start_day=end_day=that day. '
            . 'When feeding_layout is "per_day": output one feeding object per day with start_day=end_day=that day; '
            . 'do NOT merge consecutive days even if feed type and quantity are identical. '
            . 'feeding_day is optional and, if present, must equal start_day. '
            . 'CRITICAL: For each feeding item, set feed_type_name to one of the available feed types (exact match) when possible. '
            . 'JSON schema: {"feeding_layout":"range"|"per_day","feeding_layout_reason":string|null,'
            . '"vaccinations":[{age_days:int,name:string,dose:int,withdrawal_period_days:int|null,storage_instructions:string|null,description:string|null,confidence:number|null,notes:string|null}],'
            . '"medications":[{age_days:int,name:string,dose:int,withdrawal_period_days:int|null,storage_instructions:string|null,description:string|null,confidence:number|null,notes:string|null}],'
            . '"feedings":[{start_day:int,end_day:int|null,feeding_day:int|null,name:string,description:string|null,feed_type_name:string|null,quantity:number|null,feeding_times:[{time:string,percentage:number}],confidence:number|null,notes:string|null}]}. '
            . 'If a field is missing, use null (or omit optional fields).';

        $userText = 'Extract schedules from this document for poultry_type_id=' . $poultryTypeId . ".\n\n"
            . $feedTypeHint . "\n\n"
            . 'Step 1: Decide feeding_layout ("range" vs "per_day") from how the feeding table is organized in the document. '
            . 'Step 2: Extract feedings using the rules for that layout. '
            . 'Important: output JSON only.';

        $raw = $this->llm->visionChatMany($system, $userText, $vision['images']);
        if (!$raw) {
            $detail = method_exists($this->llm, 'getLastError') ? $this->llm->getLastError() : null;
            $msg = 'LLM unavailable or returned empty response' . ($detail ? (': ' . $detail) : '');
            return ['ai_available' => false, 'warnings' => array_merge($warnings, [$msg])];
        }

        $json = $this->safeJsonDecode($raw);
        if (!$json) {
            return ['ai_available' => true, 'warnings' => array_merge($warnings, ['LLM response was not valid JSON'])];
        }

        DB::transaction(function () use ($draft, $raw, $json, $poultryTypeId, $feedTypeNames, &$warnings) {
            $feedingLayout = $this->resolveFeedingLayout(
                $json['feeding_layout'] ?? null,
                $json['feedings'] ?? []
            );
            $layoutReason = is_string($json['feeding_layout_reason'] ?? null)
                ? trim($json['feeding_layout_reason'])
                : null;

            $draft->update([
                'llm_provider' => config('llm.provider', 'openai'),
                'llm_model' => config('llm.openai.model', null),
                'llm_raw_response' => $raw,
                'feeding_layout' => $feedingLayout,
                'feeding_layout_reason' => $layoutReason ?: $this->defaultLayoutReason($feedingLayout),
            ]);

            $warnings[] = $feedingLayout === 'per_day'
                ? 'Detected day-by-day feeding table in the document.'
                : 'Detected feeding schedule with day ranges (weeks/spans).';

            ScheduleImportItem::where('schedule_import_id', $draft->id)->delete();

            foreach (($json['vaccinations'] ?? []) as $it) {
                ScheduleImportItem::create([
                    'schedule_import_id' => $draft->id,
                    'kind' => 'vaccination',
                    'age_days' => isset($it['age_days']) ? (int) $it['age_days'] : null,
                    'name' => $it['name'] ?? null,
                    'dose' => isset($it['dose']) ? (int) $it['dose'] : null,
                    'withdrawal_period_days' => isset($it['withdrawal_period_days']) ? (int) $it['withdrawal_period_days'] : null,
                    'storage_instructions' => $it['storage_instructions'] ?? null,
                    'description' => $it['description'] ?? null,
                    'confidence' => $it['confidence'] ?? null,
                    'notes' => $it['notes'] ?? null,
                ]);
            }

            foreach (($json['medications'] ?? []) as $it) {
                ScheduleImportItem::create([
                    'schedule_import_id' => $draft->id,
                    'kind' => 'medication',
                    'age_days' => isset($it['age_days']) ? (int) $it['age_days'] : null,
                    'name' => $it['name'] ?? null,
                    'dose' => isset($it['dose']) ? (int) $it['dose'] : null,
                    'withdrawal_period_days' => isset($it['withdrawal_period_days']) ? (int) $it['withdrawal_period_days'] : null,
                    'storage_instructions' => $it['storage_instructions'] ?? null,
                    'description' => $it['description'] ?? null,
                    'confidence' => $it['confidence'] ?? null,
                    'notes' => $it['notes'] ?? null,
                ]);
            }

            $feedingRows = [];
            foreach (($json['feedings'] ?? []) as $it) {
                $feedTypeId = null;
                $feedTypeName = $it['feed_type_name'] ?? null;
                if (is_string($feedTypeName) && $feedTypeName !== '') {
                    // Exact match (preferred)
                    $match = PoultryFeedType::where('poultry_type_id', $poultryTypeId)
                        ->where(function ($q) use ($draft) {
                            $q->where('farm_id', $draft->farm_id)->orWhereNull('farm_id');
                        })
                        ->where('name', $feedTypeName)
                        ->first();
                    if ($match) {
                        $feedTypeId = $match->id;
                    } else {
                        // Fuzzy fallback: case-insensitive contains
                        $match = PoultryFeedType::where('poultry_type_id', $poultryTypeId)
                            ->where(function ($q) use ($draft) {
                                $q->where('farm_id', $draft->farm_id)->orWhereNull('farm_id');
                            })
                            ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($feedTypeName) . '%'])
                            ->first();
                        if ($match) {
                            $feedTypeId = $match->id;
                        }
                    }
                }

                $normalized = $this->normalizeFeedingDayRange($it);
                if ($normalized === null) {
                    continue;
                }

                $feedingRows[] = [
                    'start_day' => $normalized['start_day'],
                    'end_day' => $normalized['end_day'],
                    'feeding_day' => $normalized['start_day'],
                    'name' => $it['name'] ?? null,
                    'description' => $it['description'] ?? null,
                    'feed_type_id' => $feedTypeId,
                    'quantity' => isset($it['quantity']) ? (float) $it['quantity'] : null,
                    'feeding_times' => $it['feeding_times'] ?? [],
                    'confidence' => $it['confidence'] ?? null,
                    'notes' => $it['notes'] ?? null,
                ];
            }

            foreach ($this->finalizeFeedingRows($feedingRows, $feedingLayout) as $row) {
                ScheduleImportItem::create(array_merge(
                    ['schedule_import_id' => $draft->id, 'kind' => 'feeding'],
                    $row
                ));
            }
        });

        return ['ai_available' => true, 'warnings' => $warnings];
    }

    /**
     * @param  list<array<string, mixed>>  $rawFeedings
     */
    public function resolveFeedingLayout(?string $llmLayout, array $rawFeedings): string
    {
        $normalized = strtolower(trim((string) $llmLayout));
        if (in_array($normalized, ['range', 'per_day'], true)) {
            return $normalized;
        }

        return $this->inferFeedingLayoutFromRows($rawFeedings);
    }

    /**
     * @param  list<array<string, mixed>>  $rawFeedings
     */
    public function inferFeedingLayoutFromRows(array $rawFeedings): string
    {
        if ($rawFeedings === []) {
            return 'range';
        }

        $multiDayRanges = 0;
        $singleDays = 0;
        $signatures = [];

        foreach ($rawFeedings as $it) {
            if (!is_array($it)) {
                continue;
            }

            $normalized = $this->normalizeFeedingDayRange($it);
            if ($normalized === null) {
                continue;
            }

            $start = $normalized['start_day'];
            $end = $normalized['end_day'];

            if ($end !== null && $end > $start) {
                $multiDayRanges++;

                continue;
            }

            $singleDays++;
            $signatures[] = implode('|', [
                (string) ($it['feed_type_name'] ?? ''),
                (string) ($it['quantity'] ?? ''),
                $this->normalizeFeedingTimesKey($it['feeding_times'] ?? []),
            ]);
        }

        if ($multiDayRanges > 0) {
            return 'range';
        }

        if ($singleDays >= 2) {
            $uniqueSignatures = array_unique($signatures);
            if (count($uniqueSignatures) > 1) {
                return 'per_day';
            }

            // Multiple single-day rows with identical content: still likely a daily table.
            return 'per_day';
        }

        return 'range';
    }

    public function defaultLayoutReason(string $layout): string
    {
        return $layout === 'per_day'
            ? 'Inferred day-by-day table from extracted feeding rows.'
            : 'Inferred day-range spans from extracted feeding rows.';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function finalizeFeedingRows(array $rows, string $layout): array
    {
        if ($layout === 'per_day') {
            $expanded = [];
            foreach ($rows as $row) {
                foreach ($this->expandFeedingRowToDays($row) as $dayRow) {
                    $expanded[] = $dayRow;
                }
            }

            return $this->enforceSingleDayRows($expanded);
        }

        return $this->mergeIdenticalFeedingRanges($rows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    public function expandFeedingRowToDays(array $row): array
    {
        $start = (int) ($row['start_day'] ?? $row['feeding_day'] ?? 0);
        if ($start < 1) {
            return [];
        }

        $end = array_key_exists('end_day', $row)
            ? ($row['end_day'] === null || $row['end_day'] === '' ? null : (int) $row['end_day'])
            : $start;

        if ($end === null) {
            return [array_merge($row, [
                'start_day' => $start,
                'end_day' => null,
                'feeding_day' => $start,
            ])];
        }

        if ($end <= $start) {
            return [array_merge($row, [
                'start_day' => $start,
                'end_day' => $start,
                'feeding_day' => $start,
            ])];
        }

        $expanded = [];
        for ($day = $start; $day <= $end; $day++) {
            $expanded[] = array_merge($row, [
                'start_day' => $day,
                'end_day' => $day,
                'feeding_day' => $day,
            ]);
        }

        return $expanded;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function enforceSingleDayRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $start = (int) ($row['start_day'] ?? $row['feeding_day'] ?? 0);
            if ($start < 1) {
                continue;
            }

            $normalized[] = array_merge($row, [
                'start_day' => $start,
                'end_day' => $start,
                'feeding_day' => $start,
            ]);
        }

        usort($normalized, fn ($a, $b) => ((int) $a['start_day']) <=> ((int) $b['start_day']));

        return $normalized;
    }

    /**
     * Normalize AI feeding row into start_day / end_day.
     * - Prefer start_day/end_day when present
     * - Legacy feeding_day alone → closed 1-day range
     * - end_day null only when explicitly provided as null (open-ended)
     *
     * @param  array<string, mixed>  $it
     * @return array{start_day:int,end_day:int|null}|null
     */
    protected function normalizeFeedingDayRange(array $it): ?array
    {
        $hasStart = isset($it['start_day']) && $it['start_day'] !== '' && $it['start_day'] !== null;
        $hasFeedingDay = isset($it['feeding_day']) && $it['feeding_day'] !== '' && $it['feeding_day'] !== null;
        $hasExplicitEnd = array_key_exists('end_day', $it);

        if (!$hasStart && !$hasFeedingDay) {
            return null;
        }

        $start = $hasStart ? (int) $it['start_day'] : (int) $it['feeding_day'];
        if ($start < 1) {
            return null;
        }

        if ($hasExplicitEnd) {
            $end = ($it['end_day'] === null || $it['end_day'] === '')
                ? null
                : (int) $it['end_day'];
        } elseif ($hasStart && !$hasFeedingDay) {
            // start_day without end_day → closed 1-day by default
            $end = $start;
        } else {
            // Legacy single feeding_day (or feeding_day + start without end)
            $end = $start;
        }

        if ($end !== null && $end < $start) {
            $end = $start;
        }

        return ['start_day' => $start, 'end_day' => $end];
    }

    /**
     * Merge consecutive feeding rows that share feed type, quantity, and times into ranges.
     * Helps when the model still returns one row per day for identical weeks.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function mergeIdenticalFeedingRanges(array $rows): array
    {
        if (count($rows) <= 1) {
            return $rows;
        }

        usort($rows, function ($a, $b) {
            $startCmp = ((int) $a['start_day']) <=> ((int) $b['start_day']);
            if ($startCmp !== 0) {
                return $startCmp;
            }
            $endA = $a['end_day'] === null ? PHP_INT_MAX : (int) $a['end_day'];
            $endB = $b['end_day'] === null ? PHP_INT_MAX : (int) $b['end_day'];

            return $endA <=> $endB;
        });

        $merged = [];
        foreach ($rows as $row) {
            if (empty($merged)) {
                $merged[] = $row;
                continue;
            }

            $lastIdx = count($merged) - 1;
            $last = $merged[$lastIdx];

            if ($this->feedingRowsAreIdenticalContent($last, $row) && $this->feedingRowsAreAdjacent($last, $row)) {
                $merged[$lastIdx]['end_day'] = $row['end_day'] === null || $last['end_day'] === null
                    ? null
                    : max((int) $last['end_day'], (int) $row['end_day']);
                continue;
            }

            $merged[] = $row;
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    protected function feedingRowsAreIdenticalContent(array $a, array $b): bool
    {
        if ((int) ($a['feed_type_id'] ?? 0) !== (int) ($b['feed_type_id'] ?? 0)) {
            return false;
        }

        $qa = $a['quantity'] === null ? null : round((float) $a['quantity'], 4);
        $qb = $b['quantity'] === null ? null : round((float) $b['quantity'], 4);
        if ($qa !== $qb) {
            return false;
        }

        return $this->normalizeFeedingTimesKey($a['feeding_times'] ?? [])
            === $this->normalizeFeedingTimesKey($b['feeding_times'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    protected function feedingRowsAreAdjacent(array $a, array $b): bool
    {
        if ($a['end_day'] === null) {
            return false;
        }

        return (int) $b['start_day'] === ((int) $a['end_day'] + 1);
    }

    protected function normalizeFeedingTimesKey(mixed $times): string
    {
        if (!is_array($times)) {
            return '';
        }

        $normalized = [];
        foreach ($times as $t) {
            if (!is_array($t)) {
                continue;
            }
            $normalized[] = [
                'time' => (string) ($t['time'] ?? ''),
                'percentage' => round((float) ($t['percentage'] ?? 0), 2),
            ];
        }
        usort($normalized, fn ($x, $y) => strcmp($x['time'], $y['time']));

        return json_encode($normalized) ?: '';
    }

    /**
     * Build image input(s) for the vision model.
     * - For image: use uploaded file bytes directly
     * - For PDF: render up to N pages using Imagick if available
     */
    protected function buildVisionInputs(ScheduleImport $draft, array &$warnings): ?array
    {
        $disk = Storage::disk('public');
        $fullPath = $disk->path($draft->source_path);

        if ($draft->source_type === 'image') {
            $bytes = @file_get_contents($fullPath);
            if ($bytes === false) {
                $warnings[] = 'Failed to read image bytes';
                return null;
            }
            $mime = $this->guessMimeFromPath($fullPath) ?: 'image/png';
            return ['images' => [['mime' => $mime, 'base64' => base64_encode($bytes)]]];
        }

        if ($draft->source_type === 'pdf') {
            if (!class_exists(\Imagick::class)) {
                $warnings[] = 'PDF import requires Imagick extension to render PDF pages; please upload an image instead.';
                return null;
            }

            try {
                $maxPages = (int) config('llm.schedule_import.pdf_max_pages', 6);
                $dpi = (int) config('llm.schedule_import.pdf_render_dpi', 200);
                if ($maxPages < 1) $maxPages = 1;

                // Ensure Ghostscript is discoverable in web/PHP-FPM environments where PATH can be minimal.
                // These env vars are harmless if already set.
                $currentPath = getenv('PATH') ?: '';
                if (!str_contains($currentPath, '/usr/local/bin')) {
                    putenv('PATH=' . rtrim($currentPath, ':') . ':/usr/local/bin');
                }
                if (!str_contains(getenv('PATH') ?: '', '/opt/homebrew/bin')) {
                    putenv('PATH=' . rtrim(getenv('PATH') ?: '', ':') . ':/opt/homebrew/bin');
                }
                // Some ImageMagick builds honor this var for locating `gs`.
                if (!getenv('MAGICK_GHOSTSCRIPT_PATH')) {
                    putenv('MAGICK_GHOSTSCRIPT_PATH=/usr/local/bin');
                }

                // Determine total pages
                $probe = new \Imagick();
                $probe->pingImage($fullPath);
                $totalPages = $probe->getNumberImages();
                $probe->clear();
                $probe->destroy();

                $pagesToRender = min($totalPages, $maxPages);
                if ($totalPages > $maxPages) {
                    $warnings[] = "PDF has {$totalPages} page(s); only the first {$maxPages} page(s) will be processed.";
                }

                $images = [];
                for ($p = 0; $p < $pagesToRender; $p++) {
                    $im = new \Imagick();
                    $im->setResolution($dpi, $dpi);
                    // Some PDFs render better when using the cropbox; safe no-op if unsupported.
                    try {
                        $im->setOption('pdf:use-cropbox', 'true');
                    } catch (\Throwable $e) {
                        // ignore
                    }
                    $im->readImage($fullPath . "[{$p}]");
                    // Reduce payload size for multi-page PDFs: resize + JPEG compression.
                    // This greatly reduces base64 size and avoids LLM timeouts.
                    try {
                        $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                        $im->setImageBackgroundColor('white');
                        $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    // Resize to a reasonable width while keeping aspect ratio.
                    try {
                        $im->resizeImage(1600, 0, \Imagick::FILTER_LANCZOS, 1, true);
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    $im->setImageFormat('jpeg');
                    $im->setImageCompressionQuality(75);
                    $jpgBytes = $im->getImageBlob();
                    $im->clear();
                    $im->destroy();

                    $images[] = ['mime' => 'image/jpeg', 'base64' => base64_encode($jpgBytes)];
                }

                return ['images' => $images];
            } catch (\Throwable $e) {
                // Common causes:
                // - Imagick built without Ghostscript
                // - ImageMagick security policy blocking PDF (often: "not authorized")
                // - corrupt/encrypted PDF
                $msg = $e->getMessage();
                $warnings[] = 'Failed to render PDF for AI extraction'
                    . ($msg ? (': ' . $msg) : '')
                    . '. If this persists, ensure Ghostscript is installed and ImageMagick policy allows PDF, '
                    . 'or upload images (screenshots) of the PDF pages instead.';
                return null;
            }
        }

        $warnings[] = 'Unsupported source type';
        return null;
    }

    protected function safeJsonDecode(string $raw): ?array
    {
        $raw = trim($raw);

        // Some models wrap JSON in code fences; strip if present.
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-zA-Z0-9]*\\s*/', '', $raw);
            $raw = preg_replace('/\\s*```$/', '', $raw);
            $raw = trim($raw);
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    protected function guessMimeFromPath(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => null,
        };
    }
}

