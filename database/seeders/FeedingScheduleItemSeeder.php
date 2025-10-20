<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Models\PoultryFeedType;

class FeedingScheduleItemSeeder extends Seeder
{
    public function run()
    {
        $schedules = FeedingSchedule::all();
        $feedTypes = PoultryFeedType::all();
        foreach ($schedules as $schedule) {
            foreach (range(1, 2) as $i) {
                $feedType = PoultryFeedType::inRandomOrder()->first();
                FeedingScheduleItem::create([
                    'feeding_schedule_id' => $schedule->id,
                    'feed_type_id' => $feedType ? $feedType->id : null,
                    'feeding_times' => [
                        ['time' => '08:00', 'percentage' => 40],
                        ['time' => '17:00', 'percentage' => 60],
                    ],
                    'quantity' => 50.0 + 10.0 * ($i - 1),
                    'feeding_day' => $i,
                ]);
            }
        }
    }
} 