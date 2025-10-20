<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\PoultryType;
use App\Models\FlockStage;

class FlockStageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Temporarily disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        // Delete existing records
        DB::table('flock_stages')->delete();
        
        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Get poultry type IDs
        $poultryTypes = PoultryType::all();
        $layerType = $poultryTypes->firstWhere('name', 'Layer');
        $pulletType = $poultryTypes->firstWhere('name', 'Pullet');
        $cockerelType = $poultryTypes->firstWhere('name', 'Cockerel');

        // Layer stages
        $layerStages = [
            [
                'name' => 'Chick',
                'description' => 'Newly hatched chicks (0-8 weeks)',
                'from_age' => 0,
                'to_age' => 56,
                'poultry_type_id' => $layerType ? $layerType->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Pullet',
                'description' => 'Growing pullets (8-20 weeks)',
                'from_age' => 57,
                'to_age' => 140,
                'poultry_type_id' => $layerType ? $layerType->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Point of Lay',
                'description' => 'Pre-laying period (20-22 weeks)',
                'from_age' => 141,
                'to_age' => 154,
                'poultry_type_id' => $layerType ? $layerType->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Laying',
                'description' => 'Active laying period (22-72 weeks)',
                'from_age' => 155,
                'to_age' => 504,
                'poultry_type_id' => $layerType ? $layerType->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Molt',
                'description' => 'Natural molting period',
                'from_age' => null,
                'to_age' => null,
                'poultry_type_id' => $layerType ? $layerType->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('flock_stages')->insert($layerStages);

        // Pullet stages
        $pulletStages = [
            [
                'name' => 'Chick',
                'description' => 'Newly hatched chicks (0-8 weeks)',
                'from_age' => 0,
                'to_age' => 56,
                'poultry_type_id' => $pulletType ? $pulletType->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Growing',
                'description' => 'Growing phase (8-20 weeks)',
                'from_age' => 57,
                'to_age' => 140,
                'poultry_type_id' => $pulletType ? $pulletType->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('flock_stages')->insert($pulletStages);

        // Cockerel stages
        $cockerelStages = [
            [
                'name' => 'Chick',
                'description' => 'Newly hatched chicks (0-8 weeks)',
                'from_age' => 0,
                'to_age' => 56,
                'poultry_type_id' => $cockerelType ? $cockerelType->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Growing',
                'description' => 'Growing phase (8-20 weeks)',
                'from_age' => 57,
                'to_age' => 140,
                'poultry_type_id' => $cockerelType ? $cockerelType->id : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('flock_stages')->insert($cockerelStages);
    }
}
