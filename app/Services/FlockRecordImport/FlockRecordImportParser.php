<?php

namespace App\Services\FlockRecordImport;

use App\Models\Customer;
use App\Models\Farm;
use App\Models\FlockRecordImportItem;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;
use Carbon\Carbon;

class FlockRecordImportParser
{
    public function __construct(private readonly SpreadsheetKit $kit)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, warnings: list<string>}
     */
    public function parseFile(string $absolutePath, string $extension, Farm $farm): array
    {
        $sheets = $this->kit->readFile($absolutePath, $extension);
        $warnings = [];

        // Remap sheet titles through record-type aliases (e.g. product_sales → product_sale)
        $normalizedSheets = [];
        foreach ($sheets as $sheetName => $rows) {
            $canonical = FlockRecordImportSchema::normalizeRecordType((string) $sheetName);
            if ($canonical !== null) {
                $normalizedSheets[$canonical] = array_merge($normalizedSheets[$canonical] ?? [], $rows);
            } else {
                $normalizedSheets[(string) $sheetName] = $rows;
            }
        }
        $sheets = $normalizedSheets;

        $knownSheets = array_intersect(array_keys($sheets), FlockRecordImportSchema::sheetNames());
        if (count($knownSheets) > 0) {
            $items = [];
            $rowIndex = 0;
            foreach (FlockRecordImportSchema::sheetNames() as $type) {
                foreach ($sheets[$type] ?? [] as $row) {
                    $items[] = $this->normalizeRow($type, $row, $farm, $rowIndex++);
                }
            }

            return ['items' => $items, 'warnings' => $warnings];
        }

        // Single-sheet / CSV mode: first sheet with record_type column
        $firstSheet = reset($sheets) ?: [];
        if ($firstSheet === []) {
            return ['items' => [], 'warnings' => ['No data rows found in the uploaded file.']];
        }

        $hasRecordType = false;
        foreach ($firstSheet as $row) {
            if (array_key_exists('record_type', $row) || array_key_exists('type', $this->mapAliases($row))) {
                // Prefer explicit record_type; for product_sale "type" is product kind.
                if (array_key_exists('record_type', $row) || array_key_exists('record_type', $this->mapAliases($row))) {
                    $hasRecordType = true;
                    break;
                }
            }
        }

        // Detect via mapped aliases
        foreach ($firstSheet as $row) {
            $mapped = $this->mapAliases($row);
            if (isset($mapped['record_type']) && $mapped['record_type'] !== '') {
                $hasRecordType = true;
                break;
            }
        }

        if (! $hasRecordType) {
            return [
                'items' => [],
                'warnings' => [
                    'Could not detect sheets named after record types, and no record_type column was found. '
                    .'Use the downloadable template or add a record_type column.',
                ],
            ];
        }

        $items = [];
        $rowIndex = 0;
        foreach ($firstSheet as $row) {
            $mapped = $this->mapAliases($row);
            $type = FlockRecordImportSchema::normalizeRecordType((string) ($mapped['record_type'] ?? ''))
                ?? strtolower(trim((string) ($mapped['record_type'] ?? '')));
            unset($mapped['record_type']);

            if (! in_array($type, FlockRecordImportItem::RECORD_TYPES, true)) {
                $items[] = [
                    'record_type' => $type !== '' ? $type : 'unknown',
                    'row_index' => $rowIndex++,
                    'payload' => $mapped,
                    'confidence' => null,
                    'validation_errors' => ["Unknown record_type '{$type}'. Use: ".implode(', ', FlockRecordImportItem::RECORD_TYPES)],
                    'status' => FlockRecordImportItem::STATUS_INVALID,
                ];
                continue;
            }

            $items[] = $this->normalizeRow($type, $row, $farm, $rowIndex++, $mapped);
        }

        return ['items' => $items, 'warnings' => $warnings];
    }

    /**
     * @param  list<array<string, mixed>>  $rawItems  [{record_type, ...fields, confidence?}]
     * @return list<array<string, mixed>>
     */
    public function normalizeAiItems(array $rawItems, Farm $farm): array
    {
        $items = [];
        foreach (array_values($rawItems) as $i => $row) {
            $type = FlockRecordImportSchema::normalizeRecordType((string) ($row['record_type'] ?? ''))
                ?? strtolower(trim((string) ($row['record_type'] ?? '')));
            $confidence = isset($row['confidence']) ? (float) $row['confidence'] : null;
            unset($row['record_type'], $row['confidence']);

            if (! in_array($type, FlockRecordImportItem::RECORD_TYPES, true)) {
                $items[] = [
                    'record_type' => $type !== '' ? $type : 'unknown',
                    'row_index' => $i,
                    'payload' => $row,
                    'confidence' => $confidence,
                    'validation_errors' => ["Unknown record_type '{$type}'. Use: ".implode(', ', FlockRecordImportItem::RECORD_TYPES)],
                    'status' => FlockRecordImportItem::STATUS_INVALID,
                ];
                continue;
            }

            $item = $this->normalizeRow($type, $row, $farm, $i);
            $item['confidence'] = $confidence;
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $preMapped
     * @return array<string, mixed>
     */
    public function normalizeRow(string $type, array $row, Farm $farm, int $rowIndex, ?array $preMapped = null): array
    {
        $mapped = $preMapped ?? $this->mapAliases($row);
        unset($mapped['record_type']);

        $payload = [];
        $allowed = FlockRecordImportSchema::columns()[$type] ?? [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $mapped) && $mapped[$field] !== null && $mapped[$field] !== '') {
                $payload[$field] = $mapped[$field];
            }
        }

        // Product sale uses `type` for egg|meat|manure — ensure it survives aliasing.
        if ($type === FlockRecordImportItem::TYPE_PRODUCT_SALE && isset($mapped['type'])) {
            $payload['type'] = $mapped['type'];
        }

        if (isset($payload['date'])) {
            $payload['date'] = $this->normalizeDate($payload['date']);
        }

        $this->resolveCatalogIds($type, $payload, $farm);

        $errors = $this->validatePayload($type, $payload);

        return [
            'record_type' => $type,
            'row_index' => $rowIndex,
            'payload' => $payload,
            'confidence' => null,
            'validation_errors' => $errors === [] ? null : $errors,
            'status' => $errors === [] ? FlockRecordImportItem::STATUS_VALID : FlockRecordImportItem::STATUS_INVALID,
        ];
    }

    /**
     * Apply overlap rules across a draft item set (mutates statuses/errors).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function applyOverlapRules(array $items): array
    {
        $dailyByDate = [];
        foreach ($items as $item) {
            if (($item['record_type'] ?? '') !== FlockRecordImportItem::TYPE_DAILY) {
                continue;
            }
            $date = $item['payload']['date'] ?? null;
            if ($date) {
                $dailyByDate[$date] = $item['payload'];
            }
        }

        foreach ($items as &$item) {
            $type = $item['record_type'] ?? '';
            $date = $item['payload']['date'] ?? null;
            if (! $date || ! isset($dailyByDate[$date])) {
                continue;
            }
            $daily = $dailyByDate[$date];
            $conflict = null;

            if ($type === FlockRecordImportItem::TYPE_MORTALITY
                && (int) ($daily['mortality_count'] ?? 0) > 0) {
                $conflict = 'Conflicts with daily row on same date that already includes mortality_count.';
            }
            if ($type === FlockRecordImportItem::TYPE_EGGS
                && (int) ($daily['eggs_collected'] ?? 0) > 0) {
                $conflict = 'Conflicts with daily row on same date that already includes eggs_collected.';
            }
            if ($type === FlockRecordImportItem::TYPE_FEED_USAGE
                && (float) ($daily['feed_consumption_kg'] ?? 0) > 0) {
                $conflict = 'Conflicts with daily row on same date that already includes feed_consumption_kg.';
            }

            if ($conflict) {
                $errors = $item['validation_errors'] ?? [];
                $errors[] = $conflict;
                $item['validation_errors'] = array_values(array_unique($errors));
                $item['status'] = FlockRecordImportItem::STATUS_INVALID;
            }
        }
        unset($item);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapAliases(array $row): array
    {
        $aliases = FlockRecordImportSchema::aliases();
        $mapped = [];
        foreach ($row as $key => $value) {
            $normalized = $this->kit->normalizeHeader((string) $key);
            $canonical = $aliases[$normalized] ?? $normalized;
            if ($value === null || $value === '') {
                continue;
            }
            $mapped[$canonical] = is_string($value) ? trim($value) : $value;
        }

        return $mapped;
    }

    private function normalizeDate(mixed $value): ?string
    {
        try {
            if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
                // Excel serial date
                $unix = ((int) $value - 25569) * 86400;

                return Carbon::createFromTimestampUTC($unix)->toDateString();
            }

            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return is_string($value) ? $value : null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCatalogIds(string $type, array &$payload, Farm $farm): void
    {
        if (in_array($type, [FlockRecordImportItem::TYPE_DAILY, FlockRecordImportItem::TYPE_FEED_USAGE], true)) {
            if (empty($payload['poultry_feed_type_id']) && ! empty($payload['poultry_feed_type'])) {
                $name = (string) $payload['poultry_feed_type'];
                $feedType = PoultryFeedType::query()
                    ->where('farm_id', $farm->id)
                    ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->first();
                if ($feedType) {
                    $payload['poultry_feed_type_id'] = $feedType->id;
                }
            }

            if (! empty($payload['poultry_feed_inventory_id'])) {
                $inv = PoultryFeedInventory::query()
                    ->where('farm_id', $farm->id)
                    ->where('id', (int) $payload['poultry_feed_inventory_id'])
                    ->first();
                if (! $inv) {
                    unset($payload['poultry_feed_inventory_id']);
                } elseif (empty($payload['poultry_feed_type_id'])) {
                    $payload['poultry_feed_type_id'] = $inv->poultry_feed_type_id;
                }
            }
        }

        if (in_array($type, [FlockRecordImportItem::TYPE_FLOCK_SALE, FlockRecordImportItem::TYPE_PRODUCT_SALE], true)) {
            if (! empty($payload['customer_name']) && empty($payload['customer_id'])) {
                $customer = Customer::query()
                    ->where('farm_id', $farm->id)
                    ->whereRaw('LOWER(name) = ?', [strtolower((string) $payload['customer_name'])])
                    ->first();
                if ($customer) {
                    $payload['customer_id'] = $customer->id;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function validatePayload(string $type, array $payload): array
    {
        $errors = [];

        if (empty($payload['date'])) {
            $errors[] = 'date is required';
        }

        switch ($type) {
            case FlockRecordImportItem::TYPE_MORTALITY:
                if (! isset($payload['mortality_count'])) {
                    $errors[] = 'mortality_count is required';
                }
                break;
            case FlockRecordImportItem::TYPE_EGGS:
                if (! isset($payload['eggs_collected'])) {
                    $errors[] = 'eggs_collected is required';
                }
                if (isset($payload['eggs_broken'], $payload['eggs_collected'])
                    && (int) $payload['eggs_broken'] > (int) $payload['eggs_collected']) {
                    $errors[] = 'eggs_broken cannot exceed eggs_collected';
                }
                break;
            case FlockRecordImportItem::TYPE_FEED_USAGE:
                if (! isset($payload['quantity'])) {
                    $errors[] = 'quantity is required';
                }
                if (empty($payload['poultry_feed_type_id']) && empty($payload['poultry_feed_type'])) {
                    $errors[] = 'poultry_feed_type or poultry_feed_type_id is required';
                }
                break;
            case FlockRecordImportItem::TYPE_EXPENDITURE:
                if (empty($payload['category'])) {
                    $errors[] = 'category is required';
                }
                if (! isset($payload['amount'])) {
                    $errors[] = 'amount is required';
                }
                break;
            case FlockRecordImportItem::TYPE_FLOCK_SALE:
                if (! isset($payload['quantity'])) {
                    $errors[] = 'quantity is required';
                }
                if (! isset($payload['unit_price'])) {
                    $errors[] = 'unit_price is required';
                }
                break;
            case FlockRecordImportItem::TYPE_PRODUCT_SALE:
                if (empty($payload['type']) || ! in_array(strtolower((string) $payload['type']), ['egg', 'meat', 'manure'], true)) {
                    $errors[] = 'type must be egg, meat, or manure';
                }
                if (! isset($payload['quantity'])) {
                    $errors[] = 'quantity is required';
                }
                if (! isset($payload['unit_price'])) {
                    $errors[] = 'unit_price is required';
                }
                break;
        }

        return $errors;
    }
}
