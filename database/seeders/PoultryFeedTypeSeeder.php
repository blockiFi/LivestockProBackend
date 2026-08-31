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
            [
                'name' => 'Starter',
                'description' => 'Feed for chicks',
                'poultry_type_id' => $broiler ? $broiler->id : 1,
                'type' => 'default',
                'start_age' => 1,
                'end_age' => 14,
            ],
            [
                'name' => 'Grower',
                'description' => 'Feed for growing birds',
                'poultry_type_id' => $broiler ? $broiler->id : 1,
                'type' => 'default',
                'start_age' => 15,
                'end_age' => 35,
            ],
            [
                'name' => 'Finisher',
                'description' => 'Feed for finishing broilers',
                'poultry_type_id' => $broiler ? $broiler->id : 1,
                'type' => 'default',
                'start_age' => 36,
                'end_age' => 56,
            ],
            [
                'name' => 'Layer Mash',
                'description' => 'Feed for layers',
                'poultry_type_id' => $layer ? $layer->id : 2,
                'type' => 'default',
                'start_age' => 127,
                'end_age' => null,
            ],
        ];

        foreach ($types as $type) {
            $feedType = PoultryFeedType::firstOrCreate(
                ['name' => $type['name']],
                $type
            );

            // Backfill ages on existing rows that were seeded without them.
            if ($feedType->start_age === null && isset($type['start_age'])) {
                $feedType->update([
                    'start_age' => $type['start_age'],
                    'end_age' => $type['end_age'],
                ]);
            }
        }
    }
}
