<?php

namespace App\Services\Admin;

use App\Models\BatchSchedule;
use App\Models\Farm;
use App\Models\FarmTaskInstance;
use App\Models\FarmTaskSchedule;
use App\Models\FeedComponent;
use App\Models\FeedComposition;
use App\Models\FeedingBatchSchedule;
use App\Models\FeedingSchedule;
use App\Models\Flock;
use App\Models\FlockExpenditure;
use App\Models\FlockSale;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedProduct;
use App\Models\PoultryFeedUsage;
use App\Models\SalesRecord;
use App\Models\Schedule;
use App\Models\ScheduleImport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminOperationsService
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolvePeriod(?string $from, ?string $to): array
    {
        $end = $to ? Carbon::parse($to)->endOfDay() : Carbon::now()->endOfDay();
        $start = $from ? Carbon::parse($from)->startOfDay() : $end->copy()->subDays(30)->startOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }

    public function overview(?string $from = null, ?string $to = null, ?int $farmId = null): array
    {
        [$start, $end] = $this->resolvePeriod($from, $to);

        return [
            'period' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'schedules' => $this->schedules($from, $to, $farmId)['summary'],
            'feeds' => $this->feeds($from, $to, $farmId)['summary'],
            'financial' => $this->financial($from, $to, $farmId)['summary'],
        ];
    }

    public function schedules(?string $from = null, ?string $to = null, ?int $farmId = null): array
    {
        [$start, $end] = $this->resolvePeriod($from, $to);

        $feedingQuery = FeedingSchedule::query()->when($farmId, fn ($q) => $q->where('farm_id', $farmId));
        $healthQuery = Schedule::query()->when($farmId, fn ($q) => $q->where('farm_id', $farmId));
        $taskScheduleQuery = FarmTaskSchedule::query()->when($farmId, fn ($q) => $q->where('farm_id', $farmId));
        $taskInstanceQuery = FarmTaskInstance::query()->when($farmId, fn ($q) => $q->where('farm_id', $farmId));
        $importQuery = ScheduleImport::query()->when($farmId, fn ($q) => $q->where('farm_id', $farmId));

        $healthByType = (clone $healthQuery)
            ->selectRaw('schedule_type, COUNT(*) as aggregate')
            ->groupBy('schedule_type')
            ->pluck('aggregate', 'schedule_type');

        $taskInstancesInPeriod = (clone $taskInstanceQuery)
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()]);

        $summary = [
            'feeding_schedules' => (clone $feedingQuery)->count(),
            'feeding_batch_schedules' => FeedingBatchSchedule::query()
                ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->count(),
            'health_schedules' => (clone $healthQuery)->count(),
            'health_schedules_by_type' => [
                'vaccination' => (int) ($healthByType['vaccination'] ?? 0),
                'medication' => (int) ($healthByType['medication'] ?? 0),
                'other' => (int) ($healthByType->except(['vaccination', 'medication'])->sum()),
            ],
            'batch_schedules' => BatchSchedule::query()
                ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->count(),
            'task_schedules' => (clone $taskScheduleQuery)->count(),
            'active_task_schedules' => (clone $taskScheduleQuery)->where('is_active', true)->count(),
            'task_instances_period' => (clone $taskInstancesInPeriod)->count(),
            'task_instances_completed' => (clone $taskInstancesInPeriod)->where('status', 'completed')->count(),
            'task_instances_overdue' => (clone $taskInstanceQuery)->where('status', 'overdue')->count(),
            'task_instances_pending' => (clone $taskInstanceQuery)->whereIn('status', ['pending', 'in_progress'])->count(),
            'ai_imports_total' => (clone $importQuery)->count(),
            'ai_imports_failed' => (clone $importQuery)->where('status', 'failed')->count(),
        ];

        $farmsWithSchedules = Farm::query()
            ->when($farmId, fn ($q) => $q->where('id', $farmId))
            ->where('status', true)
            ->withCount([
                'flocks as active_flocks_count' => fn ($q) => $q->where('status', 'active'),
            ])
            ->get()
            ->map(function (Farm $farm) use ($start, $end) {
                $feeding = FeedingSchedule::where('farm_id', $farm->id)->count();
                $health = Schedule::where('farm_id', $farm->id)->count();
                $tasks = FarmTaskSchedule::where('farm_id', $farm->id)->count();
                $overdue = FarmTaskInstance::where('farm_id', $farm->id)->where('status', 'overdue')->count();
                $completed = FarmTaskInstance::where('farm_id', $farm->id)
                    ->where('status', 'completed')
                    ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
                    ->count();

                return [
                    'farm_id' => $farm->id,
                    'farm_name' => $farm->name,
                    'feeding_schedules' => $feeding,
                    'health_schedules' => $health,
                    'task_schedules' => $tasks,
                    'overdue_tasks' => $overdue,
                    'completed_tasks_period' => $completed,
                    'active_flocks' => (int) ($farm->active_flocks_count ?? 0),
                    'schedule_score' => $feeding + $health + $tasks,
                ];
            })
            ->sortByDesc('schedule_score')
            ->values()
            ->take(15)
            ->all();

        $recentImports = ScheduleImport::with(['farm:id,name', 'creator:id,name'])
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'farm_id' => $row->farm_id,
                'farm_name' => $row->farm?->name,
                'status' => $row->status,
                'source_type' => $row->source_type,
                'created_by' => $row->creator?->name,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->all();

        $taskTrend = $this->dailyCounts(
            FarmTaskInstance::query()
                ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->where('status', 'completed'),
            'updated_at',
            $start,
            $end
        );

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'summary' => $summary,
            'farms' => $farmsWithSchedules,
            'recent_ai_imports' => $recentImports,
            'completed_tasks_trend' => $taskTrend,
        ];
    }

    public function feeds(?string $from = null, ?string $to = null, ?int $farmId = null): array
    {
        [$start, $end] = $this->resolvePeriod($from, $to);

        $usageQuery = PoultryFeedUsage::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereBetween('usage_date', [$start->toDateString(), $end->toDateString()]);

        $totalUsageKg = round((float) (clone $usageQuery)->sum('quantity'), 2);
        $totalUsageCost = round((float) (clone $usageQuery)->selectRaw('SUM(quantity * unit_cost) as total')->value('total'), 2);

        $summary = [
            'feed_products' => PoultryFeedProduct::query()->when($farmId, fn ($q) => $q->where('farm_id', $farmId))->count(),
            'feed_components' => FeedComponent::query()->when($farmId, fn ($q) => $q->where('farm_id', $farmId))->count(),
            'feed_compositions' => FeedComposition::query()
                ->when($farmId, function ($q) use ($farmId) {
                    $q->whereHas('product', fn ($product) => $product->where('farm_id', $farmId));
                })
                ->count(),
            'feed_inventories' => PoultryFeedInventory::query()->when($farmId, fn ($q) => $q->where('farm_id', $farmId))->count(),
            'available_inventory_kg' => round((float) PoultryFeedInventory::query()
                ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->whereIn('status', ['available', 'in_use'])
                ->sum('available_quantity'), 2),
            'usage_kg_period' => $totalUsageKg,
            'usage_cost_period' => $totalUsageCost,
        ];

        $usageByType = (clone $usageQuery)
            ->join('poultry_feed_types', 'poultry_feed_usages.poultry_feed_type_id', '=', 'poultry_feed_types.id')
            ->selectRaw('poultry_feed_types.name as feed_type, SUM(poultry_feed_usages.quantity) as total_kg, SUM(poultry_feed_usages.quantity * poultry_feed_usages.unit_cost) as total_cost')
            ->groupBy('poultry_feed_types.name')
            ->orderByDesc('total_kg')
            ->get()
            ->map(fn ($row) => [
                'feed_type' => $row->feed_type,
                'total_kg' => round((float) $row->total_kg, 2),
                'total_cost' => round((float) $row->total_cost, 2),
            ])
            ->values()
            ->all();

        $topProducts = PoultryFeedProduct::with(['farm:id,name', 'feedType:id,name'])
            ->withCount('compositions')
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->orderByDesc('compositions_count')
            ->limit(12)
            ->get()
            ->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'farm_id' => $product->farm_id,
                'farm_name' => $product->farm?->name,
                'feed_type' => $product->feedType?->name,
                'crude_protein' => $product->crude_protein,
                'price' => $product->price,
                'compositions_count' => $product->compositions_count,
                'status' => $product->status,
            ])
            ->all();

        $farmUsage = PoultryFeedUsage::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereBetween('usage_date', [$start->toDateString(), $end->toDateString()])
            ->join('farms', 'poultry_feed_usages.farm_id', '=', 'farms.id')
            ->selectRaw('farms.id as farm_id, farms.name as farm_name, SUM(poultry_feed_usages.quantity) as total_kg, SUM(poultry_feed_usages.quantity * poultry_feed_usages.unit_cost) as total_cost')
            ->groupBy('farms.id', 'farms.name')
            ->orderByDesc('total_kg')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'farm_id' => $row->farm_id,
                'farm_name' => $row->farm_name,
                'total_kg' => round((float) $row->total_kg, 2),
                'total_cost' => round((float) $row->total_cost, 2),
            ])
            ->all();

        $usageTrend = $this->dailyFeedUsage($start, $end, $farmId);

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'summary' => $summary,
            'usage_by_type' => $usageByType,
            'top_formulated_products' => $topProducts,
            'farms_by_usage' => $farmUsage,
            'usage_trend' => $usageTrend,
        ];
    }

    public function financial(?string $from = null, ?string $to = null, ?int $farmId = null): array
    {
        [$start, $end] = $this->resolvePeriod($from, $to);
        $excludedFlockIds = $this->activeBroilerFlockIds($farmId);

        $liveBirdRevenue = $this->sumAmount(
            FlockSale::query()
                ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString()),
            'total_amount',
            $excludedFlockIds
        );

        $productRevenue = $this->sumProductRevenue($start, $end, $farmId, $excludedFlockIds);
        $totalProductRevenue = array_sum($productRevenue);
        $totalRevenue = round($liveBirdRevenue + $totalProductRevenue, 2);

        $totalCost = $this->sumAmount(
            FlockExpenditure::query()
                ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString()),
            'amount',
            $excludedFlockIds
        );

        $netProfit = round($totalRevenue - $totalCost, 2);
        $marginPercent = $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 2) : 0.0;

        $birdsSold = (int) $this->sumAmount(
            FlockSale::query()
                ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString()),
            'quantity',
            $excludedFlockIds
        );

        $summary = [
            'total_revenue' => $totalRevenue,
            'total_cost' => $totalCost,
            'net_profit' => $netProfit,
            'margin_percent' => $marginPercent,
            'birds_sold' => $birdsSold,
            'revenue_by_type' => [
                'live_bird' => round($liveBirdRevenue, 2),
                'egg' => round($productRevenue['egg'] ?? 0, 2),
                'meat' => round($productRevenue['meat'] ?? 0, 2),
                'manure' => round($productRevenue['manure'] ?? 0, 2),
            ],
        ];

        $costByCategory = $this->aggregateCostByCategory($start, $end, $farmId, $excludedFlockIds);
        $revenueTrend = $this->dailyFinancialSeries($start, $end, $farmId, $excludedFlockIds);
        $farmRankings = $this->farmFinancialRankings($start, $end, $farmId);

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'summary' => $summary,
            'cost_by_category' => $costByCategory,
            'revenue_trend' => $revenueTrend,
            'farm_rankings' => $farmRankings,
            'notes' => [
                'Active in-progress broiler batches are excluded from P&L totals.',
            ],
        ];
    }

    /**
     * @return list<int>
     */
    private function activeBroilerFlockIds(?int $farmId = null): array
    {
        return Flock::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
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
    private function sumAmount($query, string $column, array $excludedFlockIds): float
    {
        $scoped = clone $query;
        $this->applyFlockExclusions($scoped, $excludedFlockIds);

        return (float) $scoped->sum($column);
    }

    /**
     * @param  list<int>  $excludedFlockIds
     * @return array{egg: float, meat: float, manure: float}
     */
    private function sumProductRevenue(Carbon $start, Carbon $end, ?int $farmId, array $excludedFlockIds): array
    {
        $query = SalesRecord::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString());

        $this->applyFlockExclusions($query, $excludedFlockIds);

        $rows = $query
            ->selectRaw('type, COALESCE(SUM(total_amount), 0) as total')
            ->groupBy('type')
            ->get();

        return [
            'egg' => (float) ($rows->firstWhere('type', 'egg')->total ?? 0),
            'meat' => (float) ($rows->firstWhere('type', 'meat')->total ?? 0),
            'manure' => (float) ($rows->firstWhere('type', 'manure')->total ?? 0),
        ];
    }

    /**
     * @param  list<int>  $excludedFlockIds
     * @return list<array{category: string, total_cost: float}>
     */
    private function aggregateCostByCategory(Carbon $start, Carbon $end, ?int $farmId, array $excludedFlockIds): array
    {
        $query = FlockExpenditure::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString());

        $this->applyFlockExclusions($query, $excludedFlockIds);

        return $query
            ->selectRaw('category, SUM(amount) as total_cost')
            ->groupBy('category')
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'total_cost' => round((float) $row->total_cost, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $excludedFlockIds
     * @return list<array{date: string, revenue: float, cost: float, net_profit: float}>
     */
    private function dailyFinancialSeries(Carbon $start, Carbon $end, ?int $farmId, array $excludedFlockIds): array
    {
        $liveBirdQuery = FlockSale::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString());
        $this->applyFlockExclusions($liveBirdQuery, $excludedFlockIds);

        $liveBirdByDate = $liveBirdQuery
            ->selectRaw('date, SUM(total_amount) as revenue')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->toDateString());

        $productQuery = SalesRecord::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString());
        $this->applyFlockExclusions($productQuery, $excludedFlockIds);

        $productByDate = $productQuery
            ->selectRaw('date, SUM(total_amount) as revenue')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->toDateString());

        $costQuery = FlockExpenditure::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString());
        $this->applyFlockExclusions($costQuery, $excludedFlockIds);

        $costByDate = $costQuery
            ->selectRaw('date, SUM(amount) as cost')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->toDateString());

        $dates = collect($liveBirdByDate->keys())
            ->merge($productByDate->keys())
            ->merge($costByDate->keys())
            ->unique()
            ->sort()
            ->values();

        return $dates->map(function (string $date) use ($liveBirdByDate, $productByDate, $costByDate) {
            $revenue = round(
                (float) ($liveBirdByDate[$date]->revenue ?? 0) + (float) ($productByDate[$date]->revenue ?? 0),
                2
            );
            $cost = round((float) ($costByDate[$date]->cost ?? 0), 2);

            return [
                'date' => $date,
                'revenue' => $revenue,
                'cost' => $cost,
                'net_profit' => round($revenue - $cost, 2),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function farmFinancialRankings(Carbon $start, Carbon $end, ?int $farmId): array
    {
        $farms = Farm::query()
            ->when($farmId, fn ($q) => $q->where('id', $farmId))
            ->where('status', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return $farms->map(function (Farm $farm) use ($start, $end) {
            $excluded = $this->activeBroilerFlockIds($farm->id);

            $revenue = $this->sumAmount(
                FlockSale::where('farm_id', $farm->id)
                    ->whereDate('date', '>=', $start->toDateString())
                    ->whereDate('date', '<=', $end->toDateString()),
                'total_amount',
                $excluded
            ) + array_sum($this->sumProductRevenue($start, $end, $farm->id, $excluded));

            $cost = $this->sumAmount(
                FlockExpenditure::where('farm_id', $farm->id)
                    ->whereDate('date', '>=', $start->toDateString())
                    ->whereDate('date', '<=', $end->toDateString()),
                'amount',
                $excluded
            );

            $revenue = round($revenue, 2);
            $cost = round($cost, 2);

            return [
                'farm_id' => $farm->id,
                'farm_name' => $farm->name,
                'total_revenue' => $revenue,
                'total_cost' => $cost,
                'net_profit' => round($revenue - $cost, 2),
                'margin_percent' => $revenue > 0 ? round((($revenue - $cost) / $revenue) * 100, 2) : 0.0,
            ];
        })
            ->sortByDesc('total_revenue')
            ->values()
            ->take(20)
            ->all();
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    private function dailyCounts($query, string $dateColumn, Carbon $start, Carbon $end): array
    {
        $table = $query->getModel()->getTable();
        $driver = DB::connection()->getDriverName();
        $dateExpr = $driver === 'sqlite' ? "date({$table}.{$dateColumn})" : "DATE({$table}.{$dateColumn})";

        return (clone $query)
            ->whereBetween("{$table}.{$dateColumn}", [$start, $end])
            ->selectRaw("{$dateExpr} as date, COUNT(*) as count")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'count' => (int) $row->count])
            ->values()
            ->all();
    }

    /**
     * @return list<array{date: string, total_kg: float, total_cost: float}>
     */
    private function dailyFeedUsage(Carbon $start, Carbon $end, ?int $farmId): array
    {
        $query = PoultryFeedUsage::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereBetween('usage_date', [$start->toDateString(), $end->toDateString()]);

        $dateExpr = DB::connection()->getDriverName() === 'sqlite'
            ? 'date(usage_date)'
            : 'DATE(usage_date)';

        return $query
            ->selectRaw("{$dateExpr} as date, SUM(quantity) as total_kg, SUM(quantity * unit_cost) as total_cost")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->date)->toDateString(),
                'total_kg' => round((float) $row->total_kg, 2),
                'total_cost' => round((float) $row->total_cost, 2),
            ])
            ->values()
            ->all();
    }
}
