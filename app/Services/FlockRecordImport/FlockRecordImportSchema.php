<?php

namespace App\Services\FlockRecordImport;

use App\Models\FlockRecordImportItem;

class FlockRecordImportSchema
{
    public const MAX_ITEMS = 500;

    /**
     * Canonical columns per record type (first is typically required date).
     *
     * @return array<string, list<string>>
     */
    public static function columns(): array
    {
        return [
            FlockRecordImportItem::TYPE_DAILY => [
                'date', 'mortality_count', 'culling_count', 'feed_consumption_kg',
                'poultry_feed_type', 'poultry_feed_inventory_id', 'water_consumption_liters',
                'eggs_collected', 'eggs_broken', 'average_weight_kg', 'notes',
            ],
            FlockRecordImportItem::TYPE_MORTALITY => [
                'date', 'mortality_count', 'average_weight', 'notes',
            ],
            FlockRecordImportItem::TYPE_EGGS => [
                'date', 'eggs_collected', 'eggs_broken', 'average_egg_weight', 'notes',
            ],
            FlockRecordImportItem::TYPE_FEED_USAGE => [
                'date', 'quantity', 'poultry_feed_type', 'poultry_feed_type_id',
                'poultry_feed_inventory_id', 'unit_cost',
            ],
            FlockRecordImportItem::TYPE_EXPENDITURE => [
                'date', 'category', 'amount', 'currency', 'description',
                'payment_method', 'reference_no',
            ],
            FlockRecordImportItem::TYPE_FLOCK_SALE => [
                'date', 'quantity', 'unit_price', 'customer_name', 'customer_phone', 'notes',
            ],
            FlockRecordImportItem::TYPE_PRODUCT_SALE => [
                'date', 'type', 'quantity', 'unit_price', 'customer_name',
                'customer_phone', 'payment_method', 'payment_status', 'notes',
            ],
        ];
    }

    /**
     * Header aliases → canonical field.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'record_type' => 'record_type',
            'type' => 'type',
            'date' => 'date',
            'usage_date' => 'date',
            'mortality' => 'mortality_count',
            'mortality_count' => 'mortality_count',
            'culls' => 'culling_count',
            'culling_count' => 'culling_count',
            'feed_consumed_kg' => 'feed_consumption_kg',
            'feed_consumption_kg' => 'feed_consumption_kg',
            'feed_quantity' => 'quantity',
            'quantity' => 'quantity',
            'water_consumed_liters' => 'water_consumption_liters',
            'water_consumption_liters' => 'water_consumption_liters',
            'eggs' => 'eggs_collected',
            'eggs_collected' => 'eggs_collected',
            'egg_production_count' => 'eggs_collected',
            'eggs_broken' => 'eggs_broken',
            'average_weight' => 'average_weight',
            'avg_weight' => 'average_weight',
            'average_weight_kg' => 'average_weight_kg',
            'average_egg_weight' => 'average_egg_weight',
            'egg_weight' => 'average_egg_weight',
            'feed_type' => 'poultry_feed_type',
            'poultry_feed_type' => 'poultry_feed_type',
            'feed_type_name' => 'poultry_feed_type',
            'poultry_feed_type_id' => 'poultry_feed_type_id',
            'feed_type_id' => 'poultry_feed_type_id',
            'poultry_feed_inventory_id' => 'poultry_feed_inventory_id',
            'feed_inventory_id' => 'poultry_feed_inventory_id',
            'unit_cost' => 'unit_cost',
            'category' => 'category',
            'amount' => 'amount',
            'currency' => 'currency',
            'description' => 'description',
            'payment_method' => 'payment_method',
            'reference_no' => 'reference_no',
            'reference' => 'reference_no',
            'unit_price' => 'unit_price',
            'price' => 'unit_price',
            'customer_name' => 'customer_name',
            'customer' => 'customer_name',
            'customer_phone' => 'customer_phone',
            'phone' => 'customer_phone',
            'payment_status' => 'payment_status',
            'product_type' => 'type',
            'sale_type' => 'type',
            'notes' => 'notes',
            'note' => 'notes',
        ];
    }

    /**
     * Map common aliases (including plurals) to canonical record_type values.
     */
    public static function normalizeRecordType(?string $value): ?string
    {
        $key = strtolower(trim((string) $value));
        if ($key === '') {
            return null;
        }

        if (in_array($key, FlockRecordImportItem::RECORD_TYPES, true)) {
            return $key;
        }

        return match ($key) {
            'product_sales', 'productsale', 'product sales', 'egg_sale', 'egg_sales', 'sales' => FlockRecordImportItem::TYPE_PRODUCT_SALE,
            'flock_sales', 'bird_sale', 'bird_sales', 'live_bird_sale', 'live_bird_sales' => FlockRecordImportItem::TYPE_FLOCK_SALE,
            'egg', 'egg_report', 'egg_reports' => FlockRecordImportItem::TYPE_EGGS,
            'feed', 'feed_usages', 'feedusage' => FlockRecordImportItem::TYPE_FEED_USAGE,
            'expenditures', 'expense', 'expenses', 'cost', 'costs' => FlockRecordImportItem::TYPE_EXPENDITURE,
            'mortalities', 'death', 'deaths' => FlockRecordImportItem::TYPE_MORTALITY,
            'dailies', 'daily_record', 'daily_records' => FlockRecordImportItem::TYPE_DAILY,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function sheetNames(): array
    {
        return FlockRecordImportItem::RECORD_TYPES;
    }

    public static function permissionFor(string $recordType): string
    {
        return match ($recordType) {
            FlockRecordImportItem::TYPE_DAILY => 'create flocks',
            FlockRecordImportItem::TYPE_MORTALITY => 'create flock mortality reports',
            FlockRecordImportItem::TYPE_EGGS => 'create flock egg reports',
            FlockRecordImportItem::TYPE_FEED_USAGE => 'create feed usages',
            FlockRecordImportItem::TYPE_EXPENDITURE => 'update flocks',
            FlockRecordImportItem::TYPE_FLOCK_SALE => 'update flocks',
            FlockRecordImportItem::TYPE_PRODUCT_SALE => 'create sales',
            default => 'update flocks',
        };
    }
}
