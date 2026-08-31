<?php

namespace App\Services;

use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockExpenditure;
use App\Models\FlockSale;
use App\Models\SalesRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SalesProfitLossService
{
    /**
     * @return array<string, mixed>
     */
    public function farmSummary(int $farmId, string $dateFrom, string $dateTo): array
    {
        $excludedFlockIds = $this->activeBroilerFlockIds($farmId);
        $liveBirdRevenue = $this->sumLiveBirdRevenue($farmId, $dateFrom, $dateTo, $excludedFlockIds);
        $productRevenueByType = $this->sumProductRevenueByType($farmId, $dateFrom, $dateTo, $excludedFlockIds);
        $totalProductRevenue = array_sum($productRevenueByType);
        $totalRevenue = round($liveBirdRevenue + $totalProductRevenue, 2);
        $totalCost = round($this->sumFarmCosts($farmId, $dateFrom, $dateTo, $excludedFlockIds), 2);
        $birdsSold = $this->sumBirdsSold($farmId, $dateFrom, $dateTo, $excludedFlockIds);
        $netProfit = round($totalRevenue - $totalCost, 2);
        $marginPercent = $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 2) : 0.0;

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'total_revenue' => $totalRevenue,
            'total_cost' => $totalCost,
            'net_profit' => $netProfit,
            'margin_percent' => $marginPercent,
            'birds_sold' => $birdsSold,
            'revenue_by_type' => [
                'live_bird' => round($liveBirdRevenue, 2),
                'egg' => round($productRevenueByType['egg'] ?? 0, 2),
                'meat' => round($productRevenueByType['meat'] ?? 0, 2),
                'manure' => round($productRevenueByType['manure'] ?? 0, 2),
            ],
            'time_series' => $this->buildFarmTimeSeries($farmId, $dateFrom, $dateTo, $excludedFlockIds),
            'cost_by_category' => $this->costByCategory($farmId, $dateFrom, $dateTo, $excludedFlockIds),
            'flocks' => $this->buildFlockRows($farmId, $dateFrom, $dateTo, $excludedFlockIds),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function flockSummary(int $farmId, int $flockId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $flock = Flock::where('farm_id', $farmId)->findOrFail($flockId);

        $liveBirdQuery = FlockSale::where('farm_id', $farmId)->where('flock_id', $flockId);
        $productQuery = SalesRecord::where('farm_id', $farmId)->where('flock_id', $flockId);
        $costQuery = FlockExpenditure::where('farm_id', $farmId)->where('flock_id', $flockId);

        if ($dateFrom) {
            $liveBirdQuery->whereDate('date', '>=', $dateFrom);
            $productQuery->whereDate('date', '>=', $dateFrom);
            $costQuery->whereDate('date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $liveBirdQuery->whereDate('date', '<=', $dateTo);
            $productQuery->whereDate('date', '<=', $dateTo);
            $costQuery->whereDate('date', '<=', $dateTo);
        }

        $liveBirdRevenue = round((float) (clone $liveBirdQuery)->sum('total_amount'), 2);
        $productRevenueByType = $this->sumProductRevenueByTypeForFlock($farmId, $flockId, $dateFrom, $dateTo);
        $totalProductRevenue = round(array_sum($productRevenueByType), 2);
        $totalRevenue = round($liveBirdRevenue + $totalProductRevenue, 2);
        $totalCost = round((float) $costQuery->sum('amount'), 2);
        $birdsSold = (int) (clone $liveBirdQuery)->sum('quantity');
        $netProfit = round($totalRevenue - $totalCost, 2);
        $marginPercent = $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 2) : 0.0;
        $avgPrice = $birdsSold > 0 ? round($liveBirdRevenue / $birdsSold, 2) : 0.0;

        $revenueByDate = $this->mergeRevenueByDate(
            $this->liveBirdRevenueByDate($farmId, $flockId, $dateFrom, $dateTo),
            $this->productRevenueByDate($farmId, $flockId, $dateFrom, $dateTo)
        );

        $costByCategory = (clone $costQuery)
            ->selectRaw('category, SUM(amount) as total_cost')
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'total_cost' => round((float) $row->total_cost, 2),
            ])
            ->values()
            ->all();

        return [
            'flock_id' => (int) $flockId,
            'flock_name' => $flock->name,
            'total_revenue' => $totalRevenue,
            'live_bird_revenue' => $liveBirdRevenue,
            'product_revenue' => $totalProductRevenue,
            'total_cost' => $totalCost,
            'net_profit' => $netProfit,
            'margin_percent' => $marginPercent,
            'birds_sold' => $birdsSold,
            'average_sale_price' => $avgPrice,
            'revenue_by_type' => [
                'live_bird' => $liveBirdRevenue,
                'egg' => round($productRevenueByType['egg'] ?? 0, 2),
                'meat' => round($productRevenueByType['meat'] ?? 0, 2),
                'manure' => round($productRevenueByType['manure'] ?? 0, 2),
            ],
            'revenue_by_date' => $revenueByDate,
            'cost_by_category' => $costByCategory,
        ];
    }

    /**
     * Validate egg sale quantity against recorded production for the flock/date.
     *
     * @return array{valid: bool, message?: string, available?: float}
     */
    public function validateEggSaleQuantity(int $farmId, int $flockId, string $date, float $quantity, ?int $excludeRecordId = null): array
    {
        $dailyRecord = FlockDailyRecord::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->whereDate('date', $date)
            ->first();

        $produced = (float) ($dailyRecord?->egg_production_count ?? $dailyRecord?->eggs_collected ?? 0);

        $alreadySoldQuery = SalesRecord::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->where('type', 'egg')
            ->whereDate('date', $date);

        if ($excludeRecordId) {
            $alreadySoldQuery->where('id', '!=', $excludeRecordId);
        }

        $alreadySold = (float) $alreadySoldQuery->sum('quantity');
        $available = max(0, $produced - $alreadySold);

        if ($quantity > $available) {
            return [
                'valid' => false,
                'message' => "Cannot sell more eggs than recorded production for this date ({$available} available).",
                'available' => $available,
            ];
        }

        return ['valid' => true, 'available' => $available];
    }

    private function sumLiveBirdRevenue(
        int $farmId,
        string $dateFrom,
        string $dateTo,
        array $excludedFlockIds = []
    ): float {
        $query = FlockSale::where('farm_id', $farmId)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo);

        $this->applyFlockExclusions($query, $excludedFlockIds);

        return (float) $query->sum('total_amount');
    }

    private function sumFarmCosts(
        int $farmId,
        string $dateFrom,
        string $dateTo,
        array $excludedFlockIds = []
    ): float {
        $query = FlockExpenditure::where('farm_id', $farmId)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo);

        $this->applyFlockExclusions($query, $excludedFlockIds);

        return (float) $query->sum('amount');
    }

    private function sumBirdsSold(
        int $farmId,
        string $dateFrom,
        string $dateTo,
        array $excludedFlockIds = []
    ): int {
        $query = FlockSale::where('farm_id', $farmId)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo);

        $this->applyFlockExclusions($query, $excludedFlockIds);

        return (int) $query->sum('quantity');
    }

    /**
     * @return array{egg: float, meat: float, manure: float}
     */
    private function sumProductRevenueByType(
        int $farmId,
        string $dateFrom,
        string $dateTo,
        array $excludedFlockIds = []
    ): array {
        $query = SalesRecord::where('farm_id', $farmId)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo);

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
     * @return array{egg: float, meat: float, manure: float}
     */
    private function sumProductRevenueByTypeForFlock(
        int $farmId,
        int $flockId,
        ?string $dateFrom,
        ?string $dateTo
    ): array {
        $query = SalesRecord::where('farm_id', $farmId)->where('flock_id', $flockId);

        if ($dateFrom) {
            $query->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('date', '<=', $dateTo);
        }

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
     * @return list<array{date: string, revenue: float, cost: float, net_profit: float, revenue_live_bird: float, revenue_products: float}>
     */
    private function buildFarmTimeSeries(
        int $farmId,
        string $dateFrom,
        string $dateTo,
        array $excludedFlockIds = []
    ): array {
        $liveBirdQuery = FlockSale::where('farm_id', $farmId)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo);
        $this->applyFlockExclusions($liveBirdQuery, $excludedFlockIds);

        $liveBirdByDate = $liveBirdQuery
            ->selectRaw('date, SUM(total_amount) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->toDateString());

        $productQuery = SalesRecord::where('farm_id', $farmId)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo);
        $this->applyFlockExclusions($productQuery, $excludedFlockIds);

        $productByDate = $productQuery
            ->selectRaw('date, SUM(total_amount) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->toDateString());

        $costQuery = FlockExpenditure::where('farm_id', $farmId)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo);
        $this->applyFlockExclusions($costQuery, $excludedFlockIds);

        $costByDate = $costQuery
            ->selectRaw('date, SUM(amount) as cost')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->toDateString());

        $allDates = collect($liveBirdByDate->keys())
            ->merge($productByDate->keys())
            ->merge($costByDate->keys())
            ->unique()
            ->sort()
            ->values();

        return $allDates->map(function (string $date) use ($liveBirdByDate, $productByDate, $costByDate) {
            $liveBird = round((float) ($liveBirdByDate[$date]->revenue ?? 0), 2);
            $products = round((float) ($productByDate[$date]->revenue ?? 0), 2);
            $revenue = round($liveBird + $products, 2);
            $cost = round((float) ($costByDate[$date]->cost ?? 0), 2);

            return [
                'date' => $date,
                'revenue' => $revenue,
                'revenue_live_bird' => $liveBird,
                'revenue_products' => $products,
                'cost' => $cost,
                'net_profit' => round($revenue - $cost, 2),
            ];
        })->values()->all();
    }

    /**
     * @return list<array{category: string, total_cost: float}>
     */
    private function costByCategory(
        int $farmId,
        string $dateFrom,
        string $dateTo,
        array $excludedFlockIds = []
    ): array {
        $query = FlockExpenditure::where('farm_id', $farmId)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo);

        $this->applyFlockExclusions($query, $excludedFlockIds);

        return $query
            ->selectRaw('category, SUM(amount) as total_cost')
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'total_cost' => round((float) $row->total_cost, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFlockRows(
        int $farmId,
        string $dateFrom,
        string $dateTo,
        array $excludedFlockIds = []
    ): array {
        return Flock::where('farm_id', $farmId)
            ->with('poultryType:id,name')
            ->orderBy('name')
            ->get()
            ->reject(fn (Flock $flock) => in_array($flock->id, $excludedFlockIds, true))
            ->map(function (Flock $flock) use ($farmId, $dateFrom, $dateTo) {
                $liveBirdRevenue = (float) FlockSale::where('farm_id', $farmId)
                    ->where('flock_id', $flock->id)
                    ->whereDate('date', '>=', $dateFrom)
                    ->whereDate('date', '<=', $dateTo)
                    ->sum('total_amount');

                $productRevenue = (float) SalesRecord::where('farm_id', $farmId)
                    ->where('flock_id', $flock->id)
                    ->whereDate('date', '>=', $dateFrom)
                    ->whereDate('date', '<=', $dateTo)
                    ->sum('total_amount');

                $cost = (float) FlockExpenditure::where('farm_id', $farmId)
                    ->where('flock_id', $flock->id)
                    ->whereDate('date', '>=', $dateFrom)
                    ->whereDate('date', '<=', $dateTo)
                    ->sum('amount');

                $birdsSold = (int) FlockSale::where('farm_id', $farmId)
                    ->where('flock_id', $flock->id)
                    ->whereDate('date', '>=', $dateFrom)
                    ->whereDate('date', '<=', $dateTo)
                    ->sum('quantity');

                $totalRevenue = $liveBirdRevenue + $productRevenue;

                return [
                    'flock_id' => $flock->id,
                    'flock_name' => $flock->name,
                    'batch_number' => $flock->batch_number,
                    'status' => $flock->status,
                    'live_bird_revenue' => round($liveBirdRevenue, 2),
                    'product_revenue' => round($productRevenue, 2),
                    'total_revenue' => round($totalRevenue, 2),
                    'total_cost' => round($cost, 2),
                    'net_profit' => round($totalRevenue - $cost, 2),
                    'birds_sold' => $birdsSold,
                ];
            })
            ->filter(fn ($row) => $row['total_revenue'] > 0 || $row['total_cost'] > 0 || $row['birds_sold'] > 0)
            ->values()
            ->all();
    }

    /**
     * @return Collection<string, float>
     */
    private function liveBirdRevenueByDate(int $farmId, int $flockId, ?string $dateFrom, ?string $dateTo): Collection
    {
        $query = FlockSale::where('farm_id', $farmId)->where('flock_id', $flockId);

        if ($dateFrom) {
            $query->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('date', '<=', $dateTo);
        }

        return $query
            ->selectRaw('date, SUM(total_amount) as revenue, SUM(quantity) as birds_sold')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->mapWithKeys(fn ($row) => [
                Carbon::parse($row->date)->toDateString() => [
                    'revenue' => (float) $row->revenue,
                    'birds_sold' => (int) $row->birds_sold,
                ],
            ]);
    }

    /**
     * @return Collection<string, float>
     */
    private function productRevenueByDate(int $farmId, int $flockId, ?string $dateFrom, ?string $dateTo): Collection
    {
        $query = SalesRecord::where('farm_id', $farmId)->where('flock_id', $flockId);

        if ($dateFrom) {
            $query->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('date', '<=', $dateTo);
        }

        return $query
            ->selectRaw('date, SUM(total_amount) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->mapWithKeys(fn ($row) => [
                Carbon::parse($row->date)->toDateString() => (float) $row->revenue,
            ]);
    }

    /**
     * @param  Collection<string, array{revenue: float, birds_sold: int}>  $liveBird
     * @param  Collection<string, float>  $products
     * @return list<array{date: string, revenue: float, birds_sold: int}>
     */
    private function mergeRevenueByDate(Collection $liveBird, Collection $products): array
    {
        $dates = $liveBird->keys()->merge($products->keys())->unique()->sort()->values();

        return $dates->map(function (string $date) use ($liveBird, $products) {
            $live = $liveBird->get($date, ['revenue' => 0.0, 'birds_sold' => 0]);
            $product = (float) ($products->get($date) ?? 0);

            return [
                'date' => $date,
                'revenue' => round((float) $live['revenue'] + $product, 2),
                'birds_sold' => (int) $live['birds_sold'],
            ];
        })->values()->all();
    }

    /**
     * Active broiler batches are still in progress; exclude them from farm P&L.
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
}
