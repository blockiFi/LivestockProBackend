<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryHouse;
use App\Models\Farm;

class PoultryHouseSeeder extends Seeder
{
    public function run()
    {
        $farms = Farm::all();
        foreach ($farms as $farm) {
            foreach (range(1, 2) as $i) {
                PoultryHouse::factory()->create([
                    'farm_id' => $farm->id,
                ]);
            }
        }
    }
} 