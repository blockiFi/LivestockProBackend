<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockExpenditure;
use App\Models\FlockSale;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryType;
use App\Models\SalesRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FarmDashboardService
{
    public function __construct(
        private readonly FarmAlertService $alertService
    ) {
    }

    /**
     * Build the farm dashboard payload for the given date range.
     *
     * @param  array{view_feed?: bool, view_medication?: bool, view_vaccine?: bool}  $alertPermissions
     * @param  array{notify_low_stock?: bool, notify_schedules?: bool, notify_mortality?: bool}  $alertPreferences
     */
    public function build(Farm $farm, Carbon $startDate, Carbon $endDate, array $alertPermissions = [], array $alertPreferences = []): array
    {
        $startDate = $startDate->copy()->startOfDay();
        $endDate = $endDate->copy()->endOfDay();
        $periodDays = $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1;

        $prevEnd = $startDate->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays($periodDays - 1)->startOfDay();

        $kpis = $this->computeKpis($farm->id, $startDate, $endDate);
        $previous = $this->computeKpis($farm->id, $prevStart, $prevEnd);

        $flocks = Flock::where('farm_id', $farm->id)
            ->with(['poultryType', 'flockStage'])
            ->get();

        $birdsAtRisk = $this->birdsAtRiskForPeriod($flocks, $startDate, $endDate);

        return [
            'meta' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'period_days' => $periodDays,
                'previous_start_date' => $prevStart->toDateString(),
                'previous_end_date' => $prevEnd->toDateString(),
                'generated_at' => now()->toIso8601String(),
            ],
            'kpis' => $kpis,
            'previous_period' => $previous,
            'series' => $this->buildSeries($farm->id, $startDate, $endDate, $birdsAtRisk),
            'flock_distribution' => $this->buildDistribution($flocks),
            'flocks' => $this->buildFlockRows($farm->id, $flocks, $startDate, $endDate),
            'cost_by_category' => $this->costByCategory($farm->id, $startDate, $endDate),
            'alerts' => $this->alertService->forFarm($farm, null, $alertPermissions, $alertPreferences),
        ];
    }

    /**
     * Resolve start/end dates from preset or explicit params. Defaults to last 30 days.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolveDateRange(?string $preset, ?string $startDate, ?string $endDate, ?Farm $farm = null): array
    {
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        if ($startDate && $endDate) {
            return [Carbon::parse($startDate)->startOfDay(), $end];
        }

        return match ($preset) {
            '7d' => [Carbon::now()->subDays(6)->startOfDay(), $end],
            '90d' => [Carbon::now()->subDays(89)->startOfDay(), $end],
            'ytd' => [Carbon::now()->startOfYear()->startOfDay(), $end],
            'lifetime' => [$this->resolveLifetimeStart($farm), $end],
            default => [Carbon::now()->subDays(29)->startOfDay(), $end], // 30d
        };
    }

    /**
     * Earliest meaningful activity for the farm, falling back to the last 30 days.
     */
    private function resolveLifetimeStart(?Farm $farm): Carbon
    {
        if (!$farm) {
            return Carbon::now()->subDays(29)->startOfDay();
        }

        $activityDates = array_filter([
            Flock::where('farm_id', $farm->id)->min('arrival_date'),
            PoultryFeedUsage::where('farm_id', $farm->id)->min('usage_date'),
            FlockDailyRecord::where('farm_id', $farm->id)->min('date'),
            FlockSale::where('farm_id', $farm->id)->min('date'),
            FlockExpenditure::where('farm_id', $farm->id)->min('date'),
        ]);

        if (!empty($activityDates)) {
            return Carbon::parse(min($activityDates))->startOfDay();
        }

        if ($farm->established_date) {
            return Carbon::parse($farm->established_date)->startOfDay();
        }

        return Carbon::now()->subDays(29)->startOfDay();
    }

    /**
     * @return array<string, float|int>
     */
    private function computeKpis(int $farmId, Carbon $startDate, Carbon $endDate): array
    {
        $excludedFlockIds = $this->activeBroilerFlockIds($farmId);
        $flocks = Flock::where('farm_id', $farmId)->get();
        $active = $flocks->where('status', 'active');
        $activeBirds = (int) $active->sum(fn (Flock $f) => $this->birdCount($f));
        $totalBirds = $activeBirds;
        $birdsAtRisk = $this->birdsAtRiskForPeriod($flocks, $startDate, $endDate);

        $feedAgg = PoultryFeedUsage::where('farm_id', $farmId)
            ->whereBetween('usage_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('COALESCE(SUM(quantity), 0) as feed_kg, COALESCE(SUM(quantity * unit_cost), 0) as feed_cost')
            ->first();

        $feedKg = round((float) ($feedAgg->feed_kg ?? 0), 2);
        $feedCost = round((float) ($feedAgg->feed_cost ?? 0), 2);

        $eggs = (int) FlockDailyRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('COALESCE(SUM(COALESCE(egg_production_count, eggs_collected, 0)), 0) as eggs')
            ->value('eggs');

        $mortality = (int) FlockDailyRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('COALESCE(SUM(COALESCE(mortality_count, mortality, 0)), 0) as mortality')
            ->value('mortality');

        $mortalityRate = $birdsAtRisk > 0
            ? round(($mortality / $birdsAtRisk) * 100, 2)
            : 0.0;

        $revenue = round((float) $this->sumFlockSaleRevenue($farmId, $startDate, $endDate, $excludedFlockIds), 2);
        $revenue += round((float) $this->sumProductSalesRevenue($farmId, $startDate, $endDate, $excludedFlockIds), 2);
        $revenue = round($revenue, 2);

        $cost = round((float) $this->sumExpenditureCost($farmId, $startDate, $endDate, $excludedFlockIds), 2);

        $netProfit = round($revenue - $cost, 2);
        $marginPercent = $revenue > 0 ? round(($netProfit / $revenue) * 100, 2) : 0.0;
        $costPerBird = $activeBirds > 0 ? round($feedCost / $activeBirds, 2) : 0.0;
        $fcr = $this->computeFarmFcr($farmId, $startDate, $endDate);

        return [
            'total_birds' => $totalBirds,
            'active_birds' => $activeBirds,
            'active_flocks' => $active->count(),
            'total_flocks' => $flocks->count(),
            'feed_kg' => $feedKg,
            'feed_cost' => $feedCost,
            'eggs' => $eggs,
            'mortality' => $mortality,
            'mortality_rate_percent' => $mortalityRate,
            'fcr' => $fcr,
            'revenue' => $revenue,
            'cost' => $cost,
            'net_profit' => $netProfit,
            'margin_percent' => $marginPercent,
            'cost_per_bird' => $costPerBird,
        ];
    }

    /**
     * Birds exposed to mortality risk in the period (alive at period start, or placed mid-period).
     *
     * @param  Collection<int, Flock>  $flocks
     */
    private function birdsAtRiskForPeriod(Collection $flocks, Carbon $startDate, Carbon $endDate): int
    {
        $total = 0;

        foreach ($flocks as $flock) {
            $arrival = $flock->arrival_date
                ? Carbon::parse($flock->arrival_date)->startOfDay()
                : null;

            if ($arrival && $arrival->gt($endDate)) {
                continue;
            }

            // Placed during the period — whole placement is at risk.
            if ($arrival && $arrival->betweenIncluded($startDate->copy()->startOfDay(), $endDate->copy()->startOfDay())) {
                $total += max(0, (int) $flock->quantity);
                continue;
            }

            $atRisk = $flock->birdCountOnDate($startDate->toDateString());
            if ($atRisk > 0) {
                $total += $atRisk;
            }
        }

        // Never return 0 when there was mortality but live headcount is unavailable —
        // fall back to placement qty for flocks that overlapped the window.
        if ($total <= 0) {
            foreach ($flocks as $flock) {
                $arrival = $flock->arrival_date
                    ? Carbon::parse($flock->arrival_date)->startOfDay()
                    : null;

                if ($arrival && $arrival->gt($endDate)) {
                    continue;
                }

                $total += $this->placedBirdCount($flock);
            }
        }

        return $total;
    }

    private function birdCount(Flock $flock): int
    {
        return (int) ($flock->actual_quantity ?? $flock->quantity ?? 0);
    }

    /**
     * Initial / placement bird count — used for mortality % on sold flocks.
     */
    private function placedBirdCount(Flock $flock): int
    {
        return max(0, (int) ($flock->quantity ?? 0));
    }

    /**
     * Per-flock FCR (feed kg / weight gain kg), then bird-weighted average across flocks that have gain.
     */
    private function computeFarmFcr(int $farmId, Carbon $startDate, Carbon $endDate): float
    {
        $flocks = Flock::where('farm_id', $farmId)->where('status', 'active')->get();
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($flocks as $flock) {
            $fcr = $this->computeFlockFcr($farmId, $flock->id, $startDate, $endDate);
            if ($fcr === null) {
                continue;
            }
            $birds = max(1, $this->birdCount($flock));
            $weightedSum += $fcr * $birds;
            $weightTotal += $birds;
        }

        return $weightTotal > 0 ? round($weightedSum / $weightTotal, 2) : 0.0;
    }

    private function computeFlockFcr(int $farmId, int $flockId, Carbon $startDate, Carbon $endDate): ?float
    {
        $feedKg = (float) PoultryFeedUsage::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->whereBetween('usage_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('quantity');

        $weights = FlockDailyRecord::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotNull('avg_weight_grams')
            ->selectRaw('MIN(avg_weight_grams) as min_w, MAX(avg_weight_grams) as max_w')
            ->first();

        $gainGrams = $weights ? ((float) $weights->max_w - (float) $weights->min_w) : 0.0;
        if ($gainGrams <= 0 || $feedKg <= 0) {
            return null;
        }

        return round($feedKg / ($gainGrams / 1000), 2);
    }

    /**
     * Gapless daily series for the selected range.
     *
     * @return list<array<string, mixed>>
     */
    private function buildSeries(int $farmId, Carbon $startDate, Carbon $endDate, int $birdsAtRisk): array
    {
        $excludedFlockIds = $this->activeBroilerFlockIds($farmId);

        $feedByDate = PoultryFeedUsage::where('farm_id', $farmId)
            ->whereBetween('usage_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('DATE(usage_date) as d, COALESCE(SUM(quantity), 0) as feed_kg, COALESCE(SUM(quantity * unit_cost), 0) as feed_cost')
            ->groupBy('d')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->d)->toDateString());

        $daily = FlockDailyRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('DATE(date) as d, COALESCE(SUM(COALESCE(egg_production_count, eggs_collected, 0)), 0) as eggs, COALESCE(SUM(COALESCE(mortality_count, mortality, 0)), 0) as mortality')
            ->groupBy('d')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->d)->toDateString());

        $revenueByDate = $this->flockSaleRevenueByDate($farmId, $startDate, $endDate, $excludedFlockIds);

        $productRevenueByDate = $this->productSalesRevenueByDate($farmId, $startDate, $endDate, $excludedFlockIds);

        $costByDate = $this->expenditureCostByDate($farmId, $startDate, $endDate, $excludedFlockIds);

        $series = [];
        $cursor = $startDate->copy()->startOfDay();
        $last = $endDate->copy()->startOfDay();

        while ($cursor->lte($last)) {
            $key = $cursor->toDateString();
            $feed = $feedByDate->get($key);
            $day = $daily->get($key);
            $rev = $revenueByDate->get($key);
            $productRev = $productRevenueByDate->get($key);
            $cst = $costByDate->get($key);

            $feedKg = round((float) ($feed->feed_kg ?? 0), 2);
            $feedCost = round((float) ($feed->feed_cost ?? 0), 2);
            $eggs = (int) ($day->eggs ?? 0);
            $mortality = (int) ($day->mortality ?? 0);
            $revenue = round((float) ($rev->revenue ?? 0) + (float) ($productRev->revenue ?? 0), 2);
            $cost = round((float) ($cst->cost ?? 0), 2);
            $mortalityRate = $birdsAtRisk > 0
                ? round(($mortality / $birdsAtRisk) * 100, 2)
                : 0.0;

            $series[] = [
                'date' => $key,
                'feed_kg' => $feedKg,
                'feed_cost' => $feedCost,
                'eggs' => $eggs,
                'mortality' => $mortality,
                'mortality_rate' => $mortalityRate,
                'revenue' => $revenue,
                'cost' => $cost,
                'net_profit' => round($revenue - $cost, 2),
            ];

            $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param  Collection<int, Flock>  $flocks
     * @return list<array<string, mixed>>
     */
    private function buildDistribution(Collection $flocks): array
    {
        // Prefer active flocks; fall back to all flocks so sold/completed farms still chart.
        $active = $flocks->where('status', 'active');
        $source = $active->isNotEmpty() ? $active : $flocks;
        $useActual = $active->isNotEmpty();

        $birdFn = function (Flock $f) use ($useActual): int {
            if ($useActual) {
                return $this->birdCount($f);
            }

            // Ended flocks often have actual_quantity 0 — chart planned intake instead.
            return (int) ($f->quantity ?? 0);
        };

        $totalBirds = (int) $source->sum($birdFn);
        $types = PoultryType::all()->keyBy('id');
        $rows = [];

        foreach ($source->groupBy('poultry_type_id') as $typeId => $typeFlocks) {
            $birds = (int) $typeFlocks->sum($birdFn);
            $type = $types->get($typeId);
            $rows[] = [
                'type_id' => (int) $typeId,
                'type_name' => $type?->name ?? 'Unknown',
                'birds' => $birds,
                'flock_count' => $typeFlocks->count(),
                'percent' => $totalBirds > 0 ? round(($birds / $totalBirds) * 100, 2) : 0.0,
            ];
        }

        usort($rows, fn ($a, $b) => $b['birds'] <=> $a['birds']);

        return $rows;
    }

    /**
     * @param  Collection<int, Flock>  $flocks
     * @return list<array<string, mixed>>
     */
    private function buildFlockRows(int $farmId, Collection $flocks, Carbon $startDate, Carbon $endDate): array
    {
        $excludedFlockIds = $this->activeBroilerFlockIds($farmId);

        $feedByFlock = PoultryFeedUsage::where('farm_id', $farmId)
            ->whereBetween('usage_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('flock_id, COALESCE(SUM(quantity), 0) as feed_kg, COALESCE(SUM(quantity * unit_cost), 0) as feed_cost')
            ->groupBy('flock_id')
            ->get()
            ->keyBy('flock_id');

        $mortalityByFlock = FlockDailyRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('flock_id, COALESCE(SUM(COALESCE(mortality_count, mortality, 0)), 0) as mortality')
            ->groupBy('flock_id')
            ->get()
            ->keyBy('flock_id');

        $revenueByFlock = FlockSale::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('flock_id, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('flock_id')
            ->get()
            ->keyBy('flock_id');

        $productRevenueByFlock = SalesRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotNull('flock_id')
            ->selectRaw('flock_id, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('flock_id')
            ->get()
            ->keyBy('flock_id');

        $costByFlock = FlockExpenditure::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('flock_id, COALESCE(SUM(amount), 0) as cost')
            ->groupBy('flock_id')
            ->get()
            ->keyBy('flock_id');

        return $flocks->map(function (Flock $flock) use (
            $farmId,
            $startDate,
            $endDate,
            $excludedFlockIds,
            $feedByFlock,
            $mortalityByFlock,
            $revenueByFlock,
            $productRevenueByFlock,
            $costByFlock
        ) {
            $excludeFromProfit = in_array($flock->id, $excludedFlockIds, true);
            $birds = $this->birdCount($flock);
            $placed = $this->placedBirdCount($flock);
            $mortality = (int) ($mortalityByFlock->get($flock->id)->mortality ?? 0);
            $feed = $feedByFlock->get($flock->id);
            $feedKg = round((float) ($feed->feed_kg ?? 0), 2);
            $feedCost = round((float) ($feed->feed_cost ?? 0), 2);
            $revenue = $excludeFromProfit ? 0.0 : round(
                (float) ($revenueByFlock->get($flock->id)->revenue ?? 0)
                + (float) ($productRevenueByFlock->get($flock->id)->revenue ?? 0),
                2
            );
            $cost = $excludeFromProfit ? 0.0 : round((float) ($costByFlock->get($flock->id)->cost ?? 0), 2);
            $fcr = $this->computeFlockFcr($farmId, $flock->id, $startDate, $endDate);
            $rateBase = $placed > 0 ? $placed : $birds;

            return [
                'id' => $flock->id,
                'name' => $flock->name,
                'batch_number' => $flock->batch_number,
                'poultry_type' => $flock->poultryType->name ?? 'Unknown',
                'status' => $flock->status,
                'age_days' => $flock->arrival_date
                    ? Carbon::parse($flock->arrival_date)->diffInDays(now())
                    : 0,
                'birds' => $birds,
                'mortality_percent' => $rateBase > 0 ? round(($mortality / $rateBase) * 100, 2) : 0.0,
                'fcr' => $fcr ?? 0.0,
                'feed_kg' => $feedKg,
                'feed_cost' => $feedCost,
                'revenue' => $revenue,
                'net_profit' => round($revenue - $cost, 2),
            ];
        })->values()->all();
    }

    /**
     * @return list<array{category: string, total_cost: float}>
     */
    private function costByCategory(int $farmId, Carbon $startDate, Carbon $endDate): array
    {
        $query = FlockExpenditure::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);

        $this->applyFlockExclusions($query, $this->activeBroilerFlockIds($farmId));

        return $query
            ->selectRaw('category, COALESCE(SUM(amount), 0) as total_cost')
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(fn ($row) => [
                'category' => (string) $row->category,
                'total_cost' => round((float) $row->total_cost, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Active in-progress broiler batches are excluded from farm P&L totals.
     *
     * @return list<int>
     */
    private function activeBroilerFlockIds(int $farmId): array
    {
        return Flock::where('farm_id', $farmId)
            ->where('status', 'active')
            ->whereHas('poultryType', function ($query) {
                $query->where(function ($typeQuery) {
                    $typeQuery->whereRaw('LOWER(name) LIKE ?', ['%broiler%'])
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%meat%']);
                });
            })
            ->pluck('id')
            ->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<int>  $excludedFlockIds
     */
    private function applyFlockExclusions($query, array $excludedFlockIds): void
    {
        if ($excludedFlockIds === []) {
            return;
        }

        $query->where(function ($scopedQuery) use ($excludedFlockIds) {
            $scopedQuery->whereNull('flock_id')
                ->orWhereNotIn('flock_id', $excludedFlockIds);
        });
    }

    /**
     * @param  list<int>  $excludedFlockIds
     */
    private function sumFlockSaleRevenue(int $farmId, Carbon $startDate, Carbon $endDate, array $excludedFlockIds): float
    {
        $query = FlockSale::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);
        $this->applyFlockExclusions($query, $excludedFlockIds);

        return (float) $query->sum('total_amount');
    }

    /**
     * @param  list<int>  $excludedFlockIds
     */
    private function sumProductSalesRevenue(int $farmId, Carbon $startDate, Carbon $endDate, array $excludedFlockIds): float
    {
        $query = SalesRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);
        $this->applyFlockExclusions($query, $excludedFlockIds);

        return (float) $query->sum('total_amount');
    }

    /**
     * @param  list<int>  $excludedFlockIds
     */
    private function sumExpenditureCost(int $farmId, Carbon $startDate, Carbon $endDate, array $excludedFlockIds): float
    {
        $query = FlockExpenditure::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);
        $this->applyFlockExclusions($query, $excludedFlockIds);

        return (float) $query->sum('amount');
    }

    /**
     * @param  list<int>  $excludedFlockIds
     * @return \Illuminate\Support\Collection<string, object>
     */
    private function flockSaleRevenueByDate(int $farmId, Carbon $startDate, Carbon $endDate, array $excludedFlockIds): Collection
    {
        $query = FlockSale::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);
        $this->applyFlockExclusions($query, $excludedFlockIds);

        return $query
            ->selectRaw('DATE(date) as d, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('d')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->d)->toDateString());
    }

    /**
     * @param  list<int>  $excludedFlockIds
     * @return \Illuminate\Support\Collection<string, object>
     */
    private function productSalesRevenueByDate(int $farmId, Carbon $startDate, Carbon $endDate, array $excludedFlockIds): Collection
    {
        $query = SalesRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);
        $this->applyFlockExclusions($query, $excludedFlockIds);

        return $query
            ->selectRaw('DATE(date) as d, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('d')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->d)->toDateString());
    }

    /**
     * @param  list<int>  $excludedFlockIds
     * @return \Illuminate\Support\Collection<string, object>
     */
    private function expenditureCostByDate(int $farmId, Carbon $startDate, Carbon $endDate, array $excludedFlockIds): Collection
    {
        $query = FlockExpenditure::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);
        $this->applyFlockExclusions($query, $excludedFlockIds);

        return $query
            ->selectRaw('DATE(date) as d, COALESCE(SUM(amount), 0) as cost')
            ->groupBy('d')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->d)->toDateString());
    }
}
