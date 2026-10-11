<?php

namespace App\Services;

use App\Models\Flock;
use App\Models\PoultryFlockWeightReport;
use Carbon\Carbon;

class WeightStatisticsService
{
    public const DEFAULT_BAND_PERCENT = 10.0;

    /**
     * Compute flock weighing statistics from individual bird weights (grams).
     *
     * Uniformity is the share of birds within ±$bandPct% of the sample mean.
     *
     * @param  list<float|int>  $weightsG
     * @return array{
     *     count:int, mean:?float, median:?float, min:?float, max:?float,
     *     std_dev:?float, cv_percent:?float, uniformity_percent:?float,
     *     birds_within_band:int, band_percent:float, band_low:?float, band_high:?float,
     *     target:?float, deviation_g:?float, deviation_percent:?float
     * }
     */
    public function compute(array $weightsG, ?float $targetG = null, float $bandPct = self::DEFAULT_BAND_PERCENT): array
    {
        $weights = array_values(array_filter(
            array_map('floatval', $weightsG),
            fn (float $w) => $w > 0
        ));
        $count = count($weights);

        $empty = [
            'count' => 0,
            'mean' => null,
            'median' => null,
            'min' => null,
            'max' => null,
            'std_dev' => null,
            'cv_percent' => null,
            'uniformity_percent' => null,
            'birds_within_band' => 0,
            'band_percent' => $bandPct,
            'band_low' => null,
            'band_high' => null,
            'target' => $targetG,
            'deviation_g' => null,
            'deviation_percent' => null,
        ];

        if ($count === 0) {
            return $empty;
        }

        sort($weights);
        $mean = array_sum($weights) / $count;
        $median = $this->median($weights);

        // Sample standard deviation (n - 1), standard for flock CV.
        $stdDev = 0.0;
        if ($count > 1) {
            $sumSq = 0.0;
            foreach ($weights as $w) {
                $sumSq += ($w - $mean) ** 2;
            }
            $stdDev = sqrt($sumSq / ($count - 1));
        }

        $bandLow = $mean * (1 - $bandPct / 100);
        $bandHigh = $mean * (1 + $bandPct / 100);
        $within = count(array_filter($weights, fn (float $w) => $w >= $bandLow && $w <= $bandHigh));

        $deviationG = null;
        $deviationPct = null;
        if ($targetG !== null && $targetG > 0) {
            $deviationG = $mean - $targetG;
            $deviationPct = ($deviationG / $targetG) * 100;
        }

        return [
            'count' => $count,
            'mean' => round($mean, 1),
            'median' => round($median, 1),
            'min' => round($weights[0], 1),
            'max' => round($weights[$count - 1], 1),
            'std_dev' => round($stdDev, 2),
            'cv_percent' => $mean > 0 ? round(($stdDev / $mean) * 100, 2) : null,
            'uniformity_percent' => round(($within / $count) * 100, 2),
            'birds_within_band' => $within,
            'band_percent' => $bandPct,
            'band_low' => round($bandLow, 1),
            'band_high' => round($bandHigh, 1),
            'target' => $targetG,
            'deviation_g' => $deviationG !== null ? round($deviationG, 1) : null,
            'deviation_percent' => $deviationPct !== null ? round($deviationPct, 2) : null,
        ];
    }

    /**
     * Expected range of n normal samples in SD units (d2), used to estimate SD from min/max.
     */
    private const RANGE_TO_SD = [
        2 => 1.128, 3 => 1.693, 4 => 2.059, 5 => 2.326, 6 => 2.534, 7 => 2.704, 8 => 2.847,
        9 => 2.970, 10 => 3.078, 15 => 3.472, 20 => 3.735, 25 => 3.931, 30 => 4.086,
        40 => 4.322, 50 => 4.498, 75 => 4.806, 100 => 5.015, 150 => 5.296, 200 => 5.492,
        300 => 5.756, 500 => 6.073, 1000 => 6.483,
    ];

    /**
     * Uniformity for summary-only weighings (average/min/max, no individual weights).
     *
     * If the farm counted birds within the band, that count is exact. Otherwise SD is
     * estimated from the range (max − min) / d2(n), and uniformity is the share of a normal
     * distribution within ±band% of the mean.
     *
     * @return array{std_dev:?float, cv_percent:?float, uniformity_percent:?float, birds_within_band:?int, method:?string}
     */
    public function estimateFromSummary(
        ?float $averageG,
        ?float $minG,
        ?float $maxG,
        ?int $sampleSize,
        float $bandPct = self::DEFAULT_BAND_PERCENT,
        ?int $countedWithinBand = null
    ): array {
        $none = ['std_dev' => null, 'cv_percent' => null, 'uniformity_percent' => null, 'birds_within_band' => null, 'method' => null];

        if (! $averageG || $averageG <= 0 || ! $sampleSize || $sampleSize < 2) {
            return $none;
        }

        $stdDev = null;
        $cv = null;
        if ($minG !== null && $maxG !== null && $minG > 0 && $maxG >= $minG && $averageG >= $minG && $averageG <= $maxG) {
            $stdDev = ($maxG - $minG) / $this->rangeToSd($sampleSize);
            $cv = $stdDev / $averageG * 100;
        }

        if ($countedWithinBand !== null && $countedWithinBand >= 0 && $countedWithinBand <= $sampleSize) {
            return [
                'std_dev' => $stdDev !== null ? round($stdDev, 2) : null,
                'cv_percent' => $cv !== null ? round($cv, 2) : null,
                'uniformity_percent' => round($countedWithinBand / $sampleSize * 100, 2),
                'birds_within_band' => $countedWithinBand,
                'method' => PoultryFlockWeightReport::UNIFORMITY_COUNTED,
            ];
        }

        if ($stdDev === null) {
            return $none;
        }

        $uniformity = $stdDev <= 0
            ? 100.0
            : $this->erf(($bandPct / 100 * $averageG) / ($stdDev * M_SQRT2)) * 100;

        return [
            'std_dev' => round($stdDev, 2),
            'cv_percent' => round($cv, 2),
            'uniformity_percent' => round(min(100, max(0, $uniformity)), 2),
            'birds_within_band' => (int) round($uniformity / 100 * $sampleSize),
            'method' => PoultryFlockWeightReport::UNIFORMITY_ESTIMATED,
        ];
    }

    private function rangeToSd(int $n): float
    {
        $keys = array_keys(self::RANGE_TO_SD);
        if ($n <= $keys[0]) {
            return self::RANGE_TO_SD[$keys[0]];
        }
        $last = end($keys);
        if ($n >= $last) {
            return self::RANGE_TO_SD[$last];
        }
        $prev = $keys[0];
        foreach ($keys as $k) {
            if ($k === $n) {
                return self::RANGE_TO_SD[$k];
            }
            if ($k > $n) {
                $t = ($n - $prev) / ($k - $prev);

                return self::RANGE_TO_SD[$prev] + $t * (self::RANGE_TO_SD[$k] - self::RANGE_TO_SD[$prev]);
            }
            $prev = $k;
        }

        return self::RANGE_TO_SD[$last];
    }

    /** Abramowitz & Stegun 7.1.26 (max error 1.5e-7). */
    private function erf(float $x): float
    {
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);
        $t = 1 / (1 + 0.3275911 * $x);
        $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    /**
     * Flag likely data-entry outliers: more than 3 SD from the mean, or outside 50–200% of the median.
     *
     * @param  list<float|int>  $weightsG
     * @return list<bool> same order as input
     */
    public function outlierFlags(array $weightsG): array
    {
        $valid = array_values(array_filter(array_map('floatval', $weightsG), fn (float $w) => $w > 0));
        if (count($valid) < 3) {
            return array_map(fn ($w) => (float) $w <= 0, $weightsG);
        }

        $stats = $this->compute($valid);
        $mean = (float) $stats['mean'];
        $sd = (float) $stats['std_dev'];
        $median = (float) $stats['median'];

        return array_map(function ($w) use ($mean, $sd, $median) {
            $w = (float) $w;
            if ($w <= 0) {
                return true;
            }
            if ($sd > 0 && abs($w - $mean) > 3 * $sd) {
                return true;
            }

            return $median > 0 && ($w < 0.5 * $median || $w > 2 * $median);
        }, $weightsG);
    }

    /**
     * Average daily gain (g/day) versus the previous report for the same flock and sex.
     */
    public function dailyGain(
        int $flockId,
        string $reportDate,
        float $averageG,
        ?string $sex = null,
        ?int $excludeReportId = null
    ): ?float {
        $query = PoultryFlockWeightReport::where('flock_id', $flockId)
            ->whereDate('report_date', '<', $reportDate)
            ->orderByDesc('report_date')
            ->orderByDesc('id');

        if ($excludeReportId) {
            $query->where('id', '!=', $excludeReportId);
        }

        if ($sex && $sex !== 'mixed') {
            $query->where('sex', $sex);
        } else {
            $query->where(fn ($q) => $q->whereNull('sex')->orWhere('sex', 'mixed'));
        }

        $previous = $query->first();
        if (! $previous) {
            return null;
        }

        $previousG = $previous->average_weight_g ?? ((float) $previous->average_weight * 1000);
        if ($previousG <= 0) {
            return null;
        }

        $days = Carbon::parse($previous->report_date)->startOfDay()
            ->diffInDays(Carbon::parse($reportDate)->startOfDay());
        if ($days <= 0) {
            return null;
        }

        return round(($averageG - $previousG) / $days, 2);
    }

    public function ageDaysOn(Flock $flock, string $date): ?int
    {
        if (! $flock->arrival_date) {
            return null;
        }

        $arrival = Carbon::parse($flock->arrival_date)->startOfDay();
        $on = Carbon::parse($date)->startOfDay();
        $diff = (int) $arrival->diffInDays($on, false);

        return max(0, (int) ($flock->arrival_age_days ?? 0) + $diff);
    }

    /**
     * @param  list<float>  $sorted
     */
    private function median(array $sorted): float
    {
        $n = count($sorted);
        $mid = intdiv($n, 2);

        return $n % 2 === 0
            ? ($sorted[$mid - 1] + $sorted[$mid]) / 2
            : $sorted[$mid];
    }
}
