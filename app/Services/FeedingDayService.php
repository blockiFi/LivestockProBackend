<?php

namespace App\Services;

use App\Models\Flock;
use Carbon\Carbon;

class FeedingDayService
{
    /**
     * Schedule / program day for a flock on a given date.
     *
     * Uses bird age (arrival_age_days + days since arrival), same as medication
     * and vaccination schedule matching. Floored at 1.
     *
     * Day-old flocks (arrival_age_days 0 or 1) stay aligned with placement Day 1
     * on arrival. Older flocks (e.g. pullets arriving at age 130) match template
     * ranges such as 130–151, then 152+.
     */
    public static function feedingDayForDate(Flock $flock, string $date): int
    {
        $arrival = Carbon::parse($flock->arrival_date)->startOfDay();
        $record = Carbon::parse($date)->startOfDay();

        if ($record->lt($arrival)) {
            return max(1, (int) ($flock->arrival_age_days ?? 0));
        }

        $daysSinceArrival = (int) $arrival->diffInDays($record);
        $arrivalAge = (int) ($flock->arrival_age_days ?? 0);

        return max(1, $arrivalAge + $daysSinceArrival);
    }

    /**
     * Current bird head count for per-bird feed calculations.
     */
    public static function flockHeadCount(Flock $flock): int
    {
        return max(1, (int) ($flock->actual_quantity ?? $flock->quantity ?? 1));
    }

    /**
     * Convert total feed kg to per-bird grams.
     */
    public static function perBirdGramsFromTotalKg(float $feedKg, Flock $flock): float
    {
        $headCount = self::flockHeadCount($flock);

        return round(($feedKg * 1000) / $headCount, 2);
    }

    /**
     * Resolve actual total feed in grams for a batch schedule item.
     * Prefer stored total kg (from daily record / feed usage) over per-bird × flock size.
     */
    public static function actualTotalGrams(?float $actualTotalKg, ?float $perBirdGrams, int $flockQuantity): float
    {
        if ($actualTotalKg !== null && $actualTotalKg > 0) {
            return $actualTotalKg * 1000;
        }

        return ($perBirdGrams ?? 0) * $flockQuantity;
    }
}
