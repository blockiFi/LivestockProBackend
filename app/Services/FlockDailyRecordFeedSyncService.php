<?php

namespace App\Services;

use App\Models\Flock;
use App\Models\FlockDailyRecord;
use Carbon\Carbon;

class FlockDailyRecordFeedSyncService
{
    public const BULK_BACKFILL_NOTE = 'Auto-created from bulk feeding backfill.';

    /**
     * Create or update a flock daily record feed fields after bulk feeding backfill.
     *
     * @return array{0: FlockDailyRecord, 1: bool} record and whether it was newly created
     */
    public function syncFeedFromBulkBackfill(Flock $flock, string $date, float $feedKg): array
    {
        $feedKg = round(max(0, $feedKg), 3);
        $recordDate = Carbon::parse($date)->toDateString();

        $existing = FlockDailyRecord::where('flock_id', $flock->id)
            ->whereDate('date', $recordDate)
            ->first();

        if ($existing) {
            $existing->update([
                'feed_consumption_kg' => $feedKg,
                'feed_consumed_kg' => $feedKg,
            ]);

            return [$existing->fresh(), false];
        }

        $arrivalDate = Carbon::parse($flock->arrival_date)->startOfDay();
        $parsedDate = Carbon::parse($recordDate)->startOfDay();
        $ageDays = (int) (($flock->arrival_age_days ?? 0) + $arrivalDate->diffInDays($parsedDate));

        $record = FlockDailyRecord::create([
            'flock_id' => $flock->id,
            'farm_id' => $flock->farm_id,
            'date' => $recordDate,
            'age_days' => $ageDays,
            'total_birds' => FeedingDayService::flockHeadCount($flock),
            'mortality_count' => 0,
            'mortality' => 0,
            'culling_count' => 0,
            'culls' => 0,
            'feed_consumption_kg' => $feedKg,
            'feed_consumed_kg' => $feedKg,
            'notes' => self::BULK_BACKFILL_NOTE,
            'recorded_by' => auth()->id(),
        ]);

        return [$record, true];
    }
}
