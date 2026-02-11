<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FeedingBatchSchedule;
use App\Models\Flock;
use App\Models\FeedingSchedule;
use App\Models\PoultryFeedType;
use App\Models\FeedingBatchScheduleItem;
use App\Models\FeedingScheduleItem;
use Carbon\Carbon;

class FeedingBatchScheduleSeeder extends Seeder
{
    public function run()
    {
        $flocks = Flock::all();
        $feedingSchedules = FeedingSchedule::with('items')->get();

        foreach ($flocks as $flock) {
            // Find a feeding schedule (use first available for all flocks)
            $suitableSchedule = $feedingSchedules->first();

            if ($suitableSchedule && $suitableSchedule->items->count() > 0) {
                // Create a feeding batch schedule for this flock
                $batchSchedule = FeedingBatchSchedule::create([
                    'farm_id' => $flock->farm_id,
                    'flock_id' => $flock->id,
                    'feeding_schedule_id' => $suitableSchedule->id,
                    'status' => $this->getRandomStatus(),
                ]);

                // Create batch schedule items for each feeding schedule item
                foreach ($suitableSchedule->items as $scheduleItem) {
                    // Calculate the feeding date based on flock arrival date and feeding day
                    $feedingDate = Carbon::parse($flock->arrival_date)
                        ->addDays($scheduleItem->feeding_day - 1);

                    // Determine status based on feeding date
                    $itemStatus = $this->determineItemStatus($feedingDate);

                    // Calculate actual quantity (with some variation for completed items)
                    $actualQuantity = $scheduleItem->quantity;
                    if ($itemStatus === 'completed') {
                        // Add slight variation (-5% to +5%) for completed items
                        $variation = rand(-5, 5) / 100;
                        $actualQuantity = $scheduleItem->quantity * (1 + $variation);
                    }

                    // Create actual feeding times based on the schedule
                    $actualFeedingTime = $this->generateActualFeedingTimes(
                        $scheduleItem->feeding_times,
                        $itemStatus
                    );

                    FeedingBatchScheduleItem::create([
                        'feeding_batch_schedule_id' => $batchSchedule->id,
                        'feeding_schedule_item_id' => $scheduleItem->id,
                        'feeding_date' => $feedingDate->format('Y-m-d'),
                        'actual_feeding_time' => $actualFeedingTime,
                        'actual_quantity' => round($actualQuantity, 2),
                        'status' => $itemStatus,
                    ]);
                }
            }
        }
    }

    /**
     * Get a random status for the batch schedule
     */
    private function getRandomStatus(): string
    {
        $statuses = ['scheduled', 'in_progress', 'completed'];
        return $statuses[array_rand($statuses)];
    }

    /**
     * Determine item status based on feeding date
     */
    private function determineItemStatus(Carbon $feedingDate): string
    {
        $today = Carbon::today();
        $daysFromToday = $today->diffInDays($feedingDate, false);

        if ($daysFromToday < -1) {
            // Past date - randomly assign completed or missed
            return rand(0, 100) < 90 ? 'completed' : 'missed';
        } elseif ($daysFromToday === -1 || $daysFromToday === 0) {
            // Yesterday or today - likely completed or ongoing
            return rand(0, 100) < 70 ? 'completed' : 'scheduled';
        } else {
            // Future date
            return 'scheduled';
        }
    }

    /**
     * Generate actual feeding times based on scheduled times
     */
    private function generateActualFeedingTimes(array $scheduledTimes, string $status): array
    {
        if ($status === 'scheduled') {
            // For scheduled items, return the planned times
            return $scheduledTimes;
        }

        if ($status === 'missed') {
            // For missed items, return empty or planned times
            return $scheduledTimes;
        }

        // For completed items, add slight time variations
        $actualTimes = [];
        foreach ($scheduledTimes as $timeEntry) {
            $actualTimes[] = [
                'time' => $this->addTimeVariation($timeEntry['time']),
                'percentage' => $timeEntry['percentage'],
            ];
        }

        return $actualTimes;
    }

    /**
     * Add slight variation to feeding time (±15 minutes)
     */
    private function addTimeVariation(string $time): string
    {
        try {
            $carbonTime = Carbon::createFromFormat('H:i', $time);
            $variation = rand(-15, 15); // ±15 minutes
            $carbonTime->addMinutes($variation);
            return $carbonTime->format('H:i');
        } catch (\Exception $e) {
            // If parsing fails, return original time
            return $time;
        }
    }
} 