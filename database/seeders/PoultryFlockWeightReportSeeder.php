<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryFlockWeightReport;
use App\Models\Flock;

class PoultryFlockWeightReportSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $userIds = \App\Models\User::pluck('id')->all();
        foreach ($flocks as $flock) {
            foreach (range(1, 3) as $i) {
                $minWeight = rand(1000, 1500) / 100;
                $maxWeight = rand(1600, 2000) / 100;
                $avgWeight = ($minWeight + $maxWeight) / 2;
                PoultryFlockWeightReport::create([
                    'flock_id' => $flock->id,
                    'farm_id' => $flock->farm_id,
                    'average_weight' => $avgWeight,
                    'min_weight' => $minWeight,
                    'max_weight' => $maxWeight,
                    'number_of_birds' => rand(100, 200),
                    'sample_size' => rand(10, 30),
                    'report_date' => now()->subDays($i),
                    'recorded_by' => count($userIds) ? $userIds[array_rand($userIds)] : 1,
                    'notes' => 'Weight report note',
                ]);
            }
        }
    }
} 