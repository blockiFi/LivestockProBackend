<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Flock;
use App\Models\Farm;
use App\Models\PoultryHouse;
use App\Models\PoultryType;

class FlockSeeder extends Seeder
{
    public function run()
    {
        $farms = Farm::all();
        $houses = PoultryHouse::all();
        $types = PoultryType::all();
        foreach ($farms as $farm) {
            foreach ($houses->where('farm_id', $farm->id) as $house) {
                foreach (range(1, 2) as $i) {
                    Flock::factory()->create([
                        'farm_id' => $farm->id,
                        'house_id' => $house->id,
                        'poultry_type_id' => $types->random()->id,
                    ]);
                }
            }
        }
    }
} 