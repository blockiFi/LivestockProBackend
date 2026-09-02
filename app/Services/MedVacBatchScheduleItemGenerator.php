<?php

namespace App\Services;

use App\Models\BatchSchedule;
use App\Models\BatchScheduleItem;
use App\Models\Flock;
use App\Models\ScheduleItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MedVacBatchScheduleItemGenerator
{
    public function generateForBatchSchedule(BatchSchedule $batchSchedule): int
    {
        $batchSchedule->loadMissing(['schedule.items', 'flock']);

        $flock = $batchSchedule->flock;
        $schedule = $batchSchedule->schedule;

        if (! $flock || ! $schedule) {
            return 0;
        }

        $endDate = $flock->expected_end_date
            ? Carbon::parse($flock->expected_end_date)->startOfDay()
            : null;

        $created = 0;

        foreach ($schedule->items as $item) {
            $dates = $this->expandOccurrenceDates($item, $flock, $endDate);

            foreach ($dates as $scheduledDate) {
                $existing = BatchScheduleItem::withTrashed()
                    ->where('batch_schedule_id', $batchSchedule->id)
                    ->where('schedule_item_id', $item->id)
                    ->whereDate('scheduled_date', $scheduledDate->toDateString())
                    ->first();

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }

                    if (in_array($existing->status, ['completed', 'late'], true)) {
                        continue;
                    }

                    if ($existing->status !== 'scheduled') {
                        continue;
                    }

                    continue;
                }

                BatchScheduleItem::create([
                    'batch_schedule_id' => $batchSchedule->id,
                    'schedule_item_id' => $item->id,
                    'status' => 'scheduled',
                    'scheduled_date' => $scheduledDate->toDateString(),
                ]);

                $created++;
            }
        }

        return $created;
    }

    /**
     * @return Collection<int, Carbon>
     */
    public function expandOccurrenceDates(ScheduleItem $item, Flock $flock, ?Carbon $endDate): Collection
    {
        $ageDays = (int) ($item->age_days ?? 0);
        if ($ageDays <= 0) {
            return collect();
        }

        if ($item->is_recurring && ! $endDate) {
            return collect();
        }

        $arrivalDate = Carbon::parse($flock->arrival_date)->startOfDay();
        $arrivalAge = (int) ($flock->arrival_age_days ?? 0);

        $dates = collect();
        $age = $ageDays;
        $interval = max(1, (int) ($item->interval_days ?? 1));

        while (true) {
            $scheduledDate = $arrivalDate->copy()->addDays(max(0, $age - $arrivalAge))->startOfDay();

            if ($endDate && $scheduledDate->gt($endDate)) {
                break;
            }

            $dates->push($scheduledDate->copy());

            if (! $item->is_recurring) {
                break;
            }

            $age += $interval;
        }

        return $dates;
    }
}
