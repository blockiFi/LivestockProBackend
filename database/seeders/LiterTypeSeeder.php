<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LiterTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing liter types
        DB::table('liter_types')->truncate();

        $literTypes = [
            [
                'name' => 'Deep Litter',
                'description' => 'A traditional system where birds are kept on a floor covered with litter material like wood shavings, rice husks, or straw. This system provides good insulation and allows for natural scratching behavior.',
                'advantages' => json_encode([
                    'Lower initial investment',
                    'Better bird welfare',
                    'Natural behavior expression',
                    'Good insulation properties',
                    'Suitable for both broilers and layers'
                ]),
                'disadvantages' => json_encode([
                    'Higher labor requirements',
                    'More space needed per bird',
                    'Higher risk of disease transmission',
                    'More difficult to maintain hygiene',
                    'Higher feed wastage'
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Battery Cage',
                'description' => 'A modern intensive system where birds are kept in small wire cages, usually in multiple tiers. This system maximizes space utilization and egg collection efficiency.',
                'advantages' => json_encode([
                    'Higher stocking density',
                    'Easier management and monitoring',
                    'Better disease control',
                    'Higher egg production efficiency',
                    'Lower feed wastage'
                ]),
                'disadvantages' => json_encode([
                    'Higher initial investment',
                    'Limited bird movement',
                    'Potential welfare concerns',
                    'Requires more technical expertise',
                    'Higher energy costs'
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Free Range',
                'description' => 'A system where birds have access to outdoor areas during the day and are housed in shelters at night. This system provides the most natural environment for the birds.',
                'advantages' => json_encode([
                    'Best bird welfare',
                    'Natural behavior expression',
                    'Higher quality products',
                    'Lower feed costs',
                    'Better disease resistance'
                ]),
                'disadvantages' => json_encode([
                    'Highest space requirements',
                    'Lower production efficiency',
                    'Higher predation risk',
                    'Weather dependent',
                    'More difficult to manage'
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($literTypes as $type) {
            DB::table('liter_types')->insert($type);
        }
    }
}
