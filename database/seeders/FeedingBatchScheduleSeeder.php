<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FeedingBatchSchedule;
use App\Models\Flock;
use App\Models\FeedingSchedule;
use App\Models\PoultryFeedType;
use App\Models\FeedingBatchScheduleItem;

class FeedingBatchScheduleSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $schedules = FeedingSchedule::all();
        foreach ($flocks as $flock) {
            $schedule = $schedules->random();
            $batchSchedule = FeedingBatchSchedule::create([
                'farm_id' => $flock->farm_id,
                'flock_id' => $flock->id,
                'feeding_schedule_id' => $schedule->id,
            ]);

            $item = $schedule->items()->inRandomOrder()->first();
            $feedingDate = $item && isset($item->feeding_day) ? now()->subDays(min($item->feeding_day, 0)) : now();
            // If feeding_day is 0 or negative, use today
            if ($item && isset($item->feeding_day) && $item->feeding_day > 0) {
                $feedingDate = now()->subDays($item->feeding_day);
            } else {
                $feedingDate = now();
            }
            FeedingBatchScheduleItem::create([
                'feeding_batch_schedule_id' => $batchSchedule->id,
                'feeding_schedule_item_id' => $item ? $item->id : null,
                'actual_feeding_time' => json_encode([
                    ['time' => '08:00', 'percentage' => 40],
                    ['time' => '17:00', 'percentage' => 60],
                ]),
                'actual_quantity' => 50.0,
                'feeding_date' => $feedingDate,
            ]);
        }
    }
} 