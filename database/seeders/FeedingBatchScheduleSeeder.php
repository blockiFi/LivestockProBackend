<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FeedingBatchSchedule;
use App\Models\Flock;
use App\Models\FeedingSchedule;
use App\Models\FeedingBatchScheduleItem;
use Carbon\Carbon;

class FeedingBatchScheduleSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $feedingSchedules = FeedingSchedule::with('items')->get();

        foreach ($flocks as $flock) {
            $suitableSchedule = $feedingSchedules->first();

            if ($suitableSchedule && $suitableSchedule->items->count() > 0) {
                $batchSchedule = FeedingBatchSchedule::create([
                    'farm_id' => $flock->farm_id,
                    'flock_id' => $flock->id,
                    'feeding_schedule_id' => $suitableSchedule->id,
                    'status' => $this->getRandomStatus(),
                ]);

                // Seed a sample of days within each range (not every day for open-ended tails).
                foreach ($suitableSchedule->items as $scheduleItem) {
                    $start = (int) ($scheduleItem->start_day ?? $scheduleItem->feeding_day ?? 1);
                    $end = $scheduleItem->end_day !== null
                        ? (int) $scheduleItem->end_day
                        : $start + 13; // sample 2 weeks of an open-ended range

                    // Cap seeded days to avoid huge seed datasets on long ranges.
                    $end = min($end, $start + 27);

                    for ($day = $start; $day <= $end; $day++) {
                        $feedingDate = Carbon::parse($flock->arrival_date)->addDays($day - 1);
                        $itemStatus = $this->determineItemStatus($feedingDate);

                        $actualQuantity = $scheduleItem->quantity;
                        if ($itemStatus === 'completed') {
                            $variation = rand(-5, 5) / 100;
                            $actualQuantity = $scheduleItem->quantity * (1 + $variation);
                        }

                        FeedingBatchScheduleItem::create([
                            'feeding_batch_schedule_id' => $batchSchedule->id,
                            'feeding_schedule_item_id' => $scheduleItem->id,
                            'feeding_date' => $feedingDate->format('Y-m-d'),
                            'actual_feeding_time' => $this->generateActualFeedingTimes(
                                $scheduleItem->feeding_times ?? [],
                                $itemStatus
                            ),
                            'actual_quantity' => round((float) $actualQuantity, 2),
                            'status' => $itemStatus,
                        ]);
                    }
                }
            }
        }
    }

    private function getRandomStatus(): string
    {
        $statuses = ['scheduled', 'in_progress', 'completed'];
        return $statuses[array_rand($statuses)];
    }

    private function determineItemStatus(Carbon $feedingDate): string
    {
        $today = Carbon::today();
        $daysFromToday = $today->diffInDays($feedingDate, false);

        if ($daysFromToday < -1) {
            return rand(0, 100) < 90 ? 'completed' : 'missed';
        } elseif ($daysFromToday === -1 || $daysFromToday === 0) {
            return rand(0, 100) < 70 ? 'completed' : 'scheduled';
        }

        return 'scheduled';
    }

    private function generateActualFeedingTimes(array $scheduledTimes, string $status): array
    {
        if ($status === 'scheduled' || $status === 'missed' || empty($scheduledTimes)) {
            return $scheduledTimes;
        }

        $actualTimes = [];
        foreach ($scheduledTimes as $timeEntry) {
            $actualTimes[] = [
                'time' => $this->addTimeVariation($timeEntry['time'] ?? '08:00'),
                'percentage' => $timeEntry['percentage'] ?? 100,
            ];
        }

        return $actualTimes;
    }

    private function addTimeVariation(string $time): string
    {
        try {
            $carbonTime = Carbon::createFromFormat('H:i', $time);
            $carbonTime->addMinutes(rand(-15, 15));
            return $carbonTime->format('H:i');
        } catch (\Exception $e) {
            return $time;
        }
    }
}
