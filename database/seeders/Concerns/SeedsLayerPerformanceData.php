<?php

namespace Database\Seeders\Concerns;

use Faker\Generator;

trait SeedsLayerPerformanceData
{
    protected function layerPerformanceCurves(): array
    {
        return [
            'max_age_days' => 504,
            'weight_curve' => [
                'initial_weight' => 40,
                'peak_weight' => 1800,
                'peak_age_days' => 140,
            ],
            'feed_curve' => [
                'initial_feed' => 0.005,
                'peak_feed' => 0.120,
                'peak_age_days' => 140,
                'laying_feed' => 0.110,
            ],
            'mortality_curve' => [
                'early_mortality_rate' => 0.05,
                'weekly_decline' => 0.8,
                'base_mortality_rate' => 0.001,
            ],
            'egg_production' => [
                'start_laying_age' => 154,
                'peak_laying_age' => 210,
                'peak_laying_rate' => 0.85,
                'decline_rate' => 0.995,
            ],
        ];
    }

    protected function calculateLayerDailyMetrics(int $ageDays, int $currentQuantity, Generator $faker): array
    {
        $curves = $this->layerPerformanceCurves();

        $weight = $this->calculateLayerWeight($ageDays, $curves['weight_curve']);
        $feedPerBird = $this->calculateLayerFeedConsumption($ageDays, $curves['feed_curve']);
        $totalFeed = $feedPerBird * $currentQuantity;

        $mortalityRate = $this->calculateLayerMortalityRate($ageDays, $curves['mortality_curve']);
        $mortality = max(0, (int) round($currentQuantity * $mortalityRate));

        // Occasional single-bird culls; keep cumulative losses realistic for a 400-day flock.
        $culls = $faker->boolean(12) ? 1 : 0;

        $waterRatio = $faker->randomFloat(2, 2.0, 3.0);
        $totalWater = $feedPerBird * $waterRatio * $currentQuantity;

        $eggsCollected = 0;
        $eggsBroken = 0;
        if ($ageDays >= $curves['egg_production']['start_laying_age']) {
            $layingRate = $this->calculateLayerLayingRate($ageDays, $curves['egg_production']);
            $eggsCollected = (int) round($currentQuantity * $layingRate);
            $eggsBroken = (int) round($eggsCollected * $faker->randomFloat(3, 0.01, 0.03));
        }

        return [
            'mortality' => $mortality,
            'culls' => $culls,
            'feed_consumed_kg' => round($totalFeed, 2),
            'water_consumed_liters' => round($totalWater, 2),
            'avg_weight_grams' => (int) round($weight),
            'min_temperature' => $faker->randomFloat(1, 18.0, 22.0),
            'max_temperature' => $faker->randomFloat(1, 22.0, 28.0),
            'humidity' => $faker->randomFloat(1, 50.0, 70.0),
            'light_hours' => $faker->randomFloat(1, 14.0, 16.0),
            'eggs_collected' => $eggsCollected,
            'eggs_broken' => $eggsBroken,
        ];
    }

    protected function calculateLayerWeight(int $ageDays, array $weightCurve): float
    {
        $initialWeight = $weightCurve['initial_weight'];
        $peakWeight = $weightCurve['peak_weight'];
        $peakAge = $weightCurve['peak_age_days'];
        $growthRate = 0.1;

        $weight = $initialWeight + ($peakWeight - $initialWeight)
            * (1 / (1 + exp(-$growthRate * ($ageDays - $peakAge / 2))));

        return max($initialWeight, $weight);
    }

    protected function calculateLayerFeedConsumption(int $ageDays, array $feedCurve): float
    {
        $initialFeed = $feedCurve['initial_feed'];
        $peakFeed = $feedCurve['peak_feed'];
        $peakAge = $feedCurve['peak_age_days'];

        if ($ageDays <= $peakAge) {
            return max($initialFeed, $initialFeed + ($peakFeed - $initialFeed) * ($ageDays / $peakAge));
        }

        $declineRate = 0.995;

        return max($initialFeed, $peakFeed * pow($declineRate, $ageDays - $peakAge));
    }

    protected function calculateLayerMortalityRate(int $ageDays, array $mortalityCurve): float
    {
        if ($ageDays <= 7) {
            // ~0.8–1 bird/day in first week (~3% cumulative)
            return 0.001;
        }

        if ($ageDays <= 56) {
            return 0.00015;
        }

        return 0.00006;
    }

    protected function calculateLayerLayingRate(int $ageDays, array $eggProduction): float
    {
        $startAge = $eggProduction['start_laying_age'];
        $peakAge = $eggProduction['peak_laying_age'];
        $peakRate = $eggProduction['peak_laying_rate'];
        $declineRate = $eggProduction['decline_rate'];

        if ($ageDays < $startAge) {
            return 0;
        }

        if ($ageDays <= $peakAge) {
            $rate = $peakRate * (($ageDays - $startAge) / ($peakAge - $startAge));
        } else {
            $rate = $peakRate * pow($declineRate, $ageDays - $peakAge);
        }

        return max(0, min($rate, 0.95));
    }

    protected function layerFeedTypeNameForAge(int $ageDays): string
    {
        if ($ageDays <= 42) {
            return 'Starter';
        }

        if ($ageDays <= 112) {
            return 'Grower';
        }

        return 'Layer Mash';
    }
}
