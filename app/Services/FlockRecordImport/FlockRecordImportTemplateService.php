<?php

namespace App\Services\FlockRecordImport;

use App\Models\FlockRecordImportItem;

class FlockRecordImportTemplateService
{
    public function __construct(private readonly SpreadsheetKit $kit)
    {
    }

    public function writeToPath(string $absolutePath): void
    {
        $sheets = [
            'instructions' => [
                ['Flock Record Bulk Import Template'],
                ['Use one sheet per record type, OR a single sheet with a record_type column.'],
                ['Supported record_type values: '.implode(', ', FlockRecordImportItem::RECORD_TYPES)],
                ['Dates should be YYYY-MM-DD.'],
                ['For feed_usage, provide poultry_feed_type (name) matching a farm feed type.'],
                ['For product_sale, type must be egg, meat, or manure.'],
                ['Expenditure categories: feed, medication, vaccination, labour, transport, utilities, equipment, housing, chicks, maintenance, other'],
                ['Do not duplicate mortality/eggs/feed on the same date as a daily row that already fills those fields.'],
            ],
        ];

        foreach (FlockRecordImportSchema::columns() as $type => $columns) {
            $example = $this->exampleRow($type, $columns);
            $sheets[$type] = [
                $columns,
                $example,
            ];
        }

        // Combined single-sheet example
        $union = array_values(array_unique(array_merge(
            ['record_type'],
            ...array_values(FlockRecordImportSchema::columns())
        )));
        $combinedExample = array_fill_keys($union, '');
        $combinedExample['record_type'] = 'daily';
        $combinedExample['date'] = '2026-09-01';
        $combinedExample['mortality_count'] = 0;
        $combinedExample['feed_consumption_kg'] = 12.5;
        $sheets['combined_example'] = [
            $union,
            array_map(fn ($col) => $combinedExample[$col] ?? '', $union),
        ];

        $this->kit->writeXlsx($absolutePath, $sheets);
    }

    /**
     * @param  list<string>  $columns
     * @return list<mixed>
     */
    private function exampleRow(string $type, array $columns): array
    {
        $sample = match ($type) {
            FlockRecordImportItem::TYPE_DAILY => [
                'date' => '2026-09-01',
                'mortality_count' => 0,
                'culling_count' => 0,
                'feed_consumption_kg' => 12.5,
                'poultry_feed_type' => 'Starter Mash',
                'water_consumption_liters' => 40,
                'eggs_collected' => 0,
                'notes' => 'Example daily row',
            ],
            FlockRecordImportItem::TYPE_MORTALITY => [
                'date' => '2026-09-02',
                'mortality_count' => 2,
                'average_weight' => 1.2,
                'notes' => 'Example mortality',
            ],
            FlockRecordImportItem::TYPE_EGGS => [
                'date' => '2026-09-02',
                'eggs_collected' => 180,
                'eggs_broken' => 3,
                'average_egg_weight' => 58,
            ],
            FlockRecordImportItem::TYPE_FEED_USAGE => [
                'date' => '2026-09-02',
                'quantity' => 25,
                'poultry_feed_type' => 'Layer Mash',
            ],
            FlockRecordImportItem::TYPE_EXPENDITURE => [
                'date' => '2026-09-02',
                'category' => 'labour',
                'amount' => 15000,
                'description' => 'Farm hands',
            ],
            FlockRecordImportItem::TYPE_FLOCK_SALE => [
                'date' => '2026-09-03',
                'quantity' => 10,
                'unit_price' => 3500,
                'customer_name' => 'Walk-in',
            ],
            FlockRecordImportItem::TYPE_PRODUCT_SALE => [
                'date' => '2026-09-03',
                'type' => 'egg',
                'quantity' => 30,
                'unit_price' => 250,
            ],
            default => ['date' => '2026-09-01'],
        };

        return array_map(fn ($col) => $sample[$col] ?? '', $columns);
    }
}
