<?php

namespace App\Services;

use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\PoultryFeedUsage;
use Carbon\Carbon;

class FlockFcrService
{
    public const INITIAL_WEIGHT_GRAMS = 40;

    /**
     * FCR = feed consumed per bird (kg) before the latest weight record date
     *       divided by weight gain (kg), where gain = last weight (g) - 40g.
     */
    public function compute(Flock $flock): ?float
    {
        $farmId = (int) $flock->farm_id;
        $flockId = (int) $flock->id;

        $lastWeightReport = $flock->weightReports()
            ->orderByDesc('report_date')
            ->first(['average_weight', 'report_date', 'number_of_birds']);

        if ($lastWeightReport) {
            // Weight reports store average_weight in kg (see flock weight report UI).
            $lastWeightGrams = self::weightReportKgToGrams((float) $lastWeightReport->average_weight);
            $weightDate = Carbon::parse($lastWeightReport->report_date)->toDateString();
            $birdCount = max(1, (int) ($lastWeightReport->number_of_birds ?: $flock->quantity));
        } else {
            $lastDailyWeight = FlockDailyRecord::where('farm_id', $farmId)
                ->where('flock_id', $flockId)
                ->whereNotNull('avg_weight_grams')
                ->where('avg_weight_grams', '>', 0)
                ->orderByDesc('date')
                ->first(['avg_weight_grams', 'date']);

            if (!$lastDailyWeight) {
                return null;
            }

            $lastWeightGrams = (float) $lastDailyWeight->avg_weight_grams;
            $weightDate = Carbon::parse($lastDailyWeight->date)->toDateString();
            $birdCount = max(1, (int) $flock->quantity);
        }

        $weightGainGrams = $lastWeightGrams - self::INITIAL_WEIGHT_GRAMS;
        if ($weightGainGrams <= 0) {
            return null;
        }

        $feedFromUsages = (float) PoultryFeedUsage::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->whereDate('usage_date', '<', $weightDate)
            ->sum('quantity');

        $feedFromDaily = $this->sumDailyFeedKg(
            FlockDailyRecord::where('farm_id', $farmId)
                ->where('flock_id', $flockId)
                ->whereDate('date', '<', $weightDate)
        );

        $feedKg = max($feedFromUsages, $feedFromDaily);
        if ($feedKg <= 0) {
            return null;
        }

        $feedPerBirdKg = $feedKg / $birdCount;
        $weightGainKg = $weightGainGrams / 1000;

        return round($feedPerBirdKg / $weightGainKg, 2);
    }

    public static function weightReportKgToGrams(float $kg): float
    {
        return $kg * 1000;
    }

    private function sumDailyFeedKg($query): float
    {
        return (float) $query
            ->get(['feed_consumption_kg', 'feed_consumed_kg'])
            ->sum(fn ($record) => (float) ($record->feed_consumption_kg ?? $record->feed_consumed_kg ?? 0));
    }
}
