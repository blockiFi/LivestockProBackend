<?php

namespace App\Services;

use App\Models\Flock;
use Carbon\Carbon;

class FeedingDayService
{
    /**
     * Placement-based feeding day: Day 1 = flock arrival date.
     */
    public static function feedingDayForDate(Flock $flock, string $date): int
    {
        $arrival = Carbon::parse($flock->arrival_date)->startOfDay();
        $record = Carbon::parse($date)->startOfDay();

        return (int) $arrival->diffInDays($record) + 1;
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
