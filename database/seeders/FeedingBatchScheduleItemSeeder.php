<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FeedingBatchSchedule;
use App\Models\FeedingBatchScheduleItem;
use App\Models\FeedingScheduleItem;
use App\Models\PoultryFeedType;

class FeedingBatchScheduleItemSeeder extends Seeder
{
    public function run()
    {
        $batchSchedules = FeedingBatchSchedule::all();
        $scheduleItems = FeedingScheduleItem::all();
        foreach ($batchSchedules as $batchSchedule) {
            foreach ($scheduleItems->random(2) as $item) {
                $feedType = PoultryFeedType::inRandomOrder()->first();
                FeedingBatchScheduleItem::create([
                    'feeding_batch_schedule_id' => $batchSchedule->id,
                    'feeding_schedule_item_id' => $item->id,
                    'actual_feeding_time' => json_encode([
                        ['time' => '08:00', 'percentage' => 40],
                        ['time' => '17:00', 'percentage' => 60],
                    ]),
                    'actual_quantity' => 50.0,
                    'feeding_date' => now()->subDays($item->id % 5),
                ]);
            }
        }
    }
} 