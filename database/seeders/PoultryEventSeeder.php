<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PoultryEvent;
use App\Models\Flock;

class PoultryEventSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $userIds = \App\Models\User::pluck('id')->all();
        foreach ($flocks as $flock) {
            foreach (range(1, 3) as $i) {
                PoultryEvent::create([
                    'flock_id' => $flock->id,
                    'farm_id' => $flock->farm_id,
                    'event_type' => 'event_type_' . $i,
                    'table_name' => 'flocks',
                    'table_id' => $flock->id,
                    'event_date' => now()->subDays($i),
                    'event' => 'Event description ' . $i,
                    'performed_by' => count($userIds) ? $userIds[array_rand($userIds)] : 1,
                ]);
            }
        }
    }
} 