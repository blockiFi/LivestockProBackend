<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryMortalityReport;
use App\Models\Flock;

class PoultryMortalityReportSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $userIds = \App\Models\User::pluck('id')->all();
        foreach ($flocks as $flock) {
            foreach (range(1, 2) as $i) {
                PoultryMortalityReport::create([
                    'flock_id' => $flock->id,
                    'farm_id' => $flock->farm_id,
                    'poultry_type_id' => $flock->poultry_type_id,
                    'mortality_count' => rand(1, 10),
                    'average_weight' => rand(1000, 2000) / 100,
                    'mortality_percentage' => rand(1, 10),
                    'bird_count' => rand(100, 200),
                    'date' => now()->subDays($i),
                    'recorded_by' => count($userIds) ? $userIds[array_rand($userIds)] : 1,
                    'notes' => 'Mortality report note',
                ]);
            }
        }
    }
} 