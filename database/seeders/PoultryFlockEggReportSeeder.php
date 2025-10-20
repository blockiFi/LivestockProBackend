<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryFlockEggReport;
use App\Models\Flock;

class PoultryFlockEggReportSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $userIds = \App\Models\User::pluck('id')->all();
        foreach ($flocks as $flock) {
            foreach (range(1, 3) as $i) {
                PoultryFlockEggReport::create([
                    'flock_id' => $flock->id,
                    'farm_id' => $flock->farm_id,
                    'eggs_collected' => rand(100, 500),
                    'average_egg_weight' => rand(50, 70) / 10,
                    'production_percentage' => rand(70, 100),
                    'bird_count' => rand(100, 200),
                    'recorded_by' => count($userIds) ? $userIds[array_rand($userIds)] : 1,
                    'notes' => 'Egg report note',
                    'date' => now()->subDays($i),
                ]);
            }
        }
    }
} 