<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryFeedInventory;
use App\Models\Farm;
use App\Models\PoultryFeedType;

class PoultryFeedInventorySeeder extends Seeder
{
    public function run()
    {
        $farms = Farm::all();
        $feedTypes = PoultryFeedType::all();
        foreach ($farms as $farm) {
            foreach ($feedTypes as $type) {
                PoultryFeedInventory::create([
                    'farm_id' => $farm->id,
                    'poultry_feed_type_id' => $type->id,
                    'quantity' => 100.0,
                    'unit_cost' => 500,
                ]);
            }
        }
    }
} 