<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FlockDailyRecord;
use App\Models\Flock;

class FlockDailyRecordSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $userIds = \App\Models\User::pluck('id')->all();
        foreach ($flocks as $flock) {
            foreach (range(1, 5) as $i) {
                FlockDailyRecord::create([
                    'flock_id' => $flock->id,
                    'farm_id' => $flock->farm_id,
                    'date' => now()->subDays($i),
                    'mortality' => rand(0, 2),
                    'culls' => rand(0, 1),
                    'feed_consumed_kg' => rand(100, 200) / 10,
                    'water_consumed_liters' => rand(500, 1000) / 10,
                    'avg_weight_grams' => rand(1000, 2000),
                    'min_temperature' => rand(180, 220) / 10,
                    'max_temperature' => rand(220, 300) / 10,
                    'humidity' => rand(40, 80),
                    'light_hours' => rand(8, 16),
                    'eggs_collected' => rand(0, 200),
                    'eggs_broken' => rand(0, 10),
                    'notes' => 'Daily record note',
                    'recorded_by' => count($userIds) ? $userIds[array_rand($userIds)] : 1,
                ]);
            }
        }
    }
} 