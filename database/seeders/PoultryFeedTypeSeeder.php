<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryFeedType;
use App\Models\PoultryType;

class PoultryFeedTypeSeeder extends Seeder
{
    public function run()
    {
        $broiler = PoultryType::where('name', 'Broiler')->first();
        $layer = PoultryType::where('name', 'Layer')->first();
        $types = [
            ['name' => 'Starter', 'description' => 'Feed for chicks', 'poultry_type_id' => $broiler ? $broiler->id : 1, 'type' => 'default'],
            ['name' => 'Grower', 'description' => 'Feed for growing birds', 'poultry_type_id' => $broiler ? $broiler->id : 1, 'type' => 'default'],
            ['name' => 'Finisher', 'description' => 'Feed for finishing broilers', 'poultry_type_id' => $broiler ? $broiler->id : 1, 'type' => 'default'],
            ['name' => 'Layer Mash', 'description' => 'Feed for layers', 'poultry_type_id' => $layer ? $layer->id : 2, 'type' => 'default'],
        ];
        foreach ($types as $type) {
            PoultryFeedType::firstOrCreate(['name' => $type['name']], $type);
        }
    }
} 