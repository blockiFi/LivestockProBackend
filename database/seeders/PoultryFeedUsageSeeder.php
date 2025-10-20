<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryFeedUsage;
use App\Models\Flock;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedType;

class PoultryFeedUsageSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $inventories = PoultryFeedInventory::all();
        $feedTypes = PoultryFeedType::all();
        foreach ($flocks as $flock) {
            foreach (range(1, 3) as $i) {
                $inventory = $inventories->random();
                PoultryFeedUsage::create([
                    'flock_id' => $flock->id,
                    'farm_id' => $flock->farm_id,
                    'poultry_feed_inventory_id' => $inventory->id,
                    'poultry_feed_type_id' => $inventory->poultry_feed_type_id,
                    'quantity' => 10.0 * $i,
                    'unit_cost' => 500.0,
                    'created_by' => 1,
                    'usage_date' => now()->subDays($i),
                ]);
            }
        }
    }
} 