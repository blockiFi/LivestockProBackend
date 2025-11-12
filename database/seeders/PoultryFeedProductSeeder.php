<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryFeedProduct;
use App\Models\PoultryFeedType;

class PoultryFeedProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $feedTypes = PoultryFeedType::all();

        foreach ($feedTypes as $type) {
            for ($i = 1; $i <= 4; $i++) {
                $name = $type->name . ' Product ' . $i;
                PoultryFeedProduct::firstOrCreate(
                    ['name' => $name, 'poultry_feed_type_id' => $type->id],
                    [
                        'sku' => strtoupper(substr(preg_replace('/\s+/', '', $type->name), 0, 6)) . '-P' . $i . '-' . rand(100, 999),
                        'description' => "Auto-seeded product for {$type->name} ({$name})",
                    ]
                );
            }
        }
    }
}
