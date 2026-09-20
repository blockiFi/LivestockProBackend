<?php

use App\Support\EggMetrics;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert historical egg product sales from egg units to crate units.
 *
 * Before: quantity = eggs, unit_price = price per egg
 * After:  quantity = crates, unit_price = price per crate
 * total_amount is left unchanged (already correct money).
 */
return new class extends Migration
{
    public function up(): void
    {
        $perCrate = EggMetrics::EGGS_PER_CRATE;

        DB::table('sales_records')
            ->where('type', 'egg')
            ->update([
                'quantity' => DB::raw("quantity / {$perCrate}"),
                'unit_price' => DB::raw("unit_price * {$perCrate}"),
            ]);
    }

    public function down(): void
    {
        $perCrate = EggMetrics::EGGS_PER_CRATE;

        DB::table('sales_records')
            ->where('type', 'egg')
            ->update([
                'quantity' => DB::raw("quantity * {$perCrate}"),
                'unit_price' => DB::raw("unit_price / {$perCrate}"),
            ]);
    }
};
