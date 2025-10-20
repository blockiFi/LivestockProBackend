<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\PoultryType;
use Illuminate\Support\Facades\DB;

class PoultryTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Temporarily disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        // Delete existing records
        DB::table('poultry_types')->delete();
        
        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $types = [
            ['name' => 'Broiler', 'description' => 'Fast-growing meat bird'],
            ['name' => 'Layer', 'description' => 'Egg-laying bird'],
            ['name' => 'Cockerel', 'description' => 'Young male chicken'],
            ['name' => 'Pullet', 'description' => 'Young female chicken'],
            ['name' => 'Dual Purpose', 'description' => 'Good for both meat and eggs'],
        ];
        foreach ($types as $type) {
            PoultryType::firstOrCreate(['name' => $type['name']], $type);
        }
    }
}
