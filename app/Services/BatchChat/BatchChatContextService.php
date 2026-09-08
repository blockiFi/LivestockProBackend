<?php

namespace App\Services\BatchChat;

use App\Models\BatchScheduleItem;
use App\Models\Farm;
use App\Models\FeedingBatchScheduleItem;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockExpenditure;
use App\Models\FlockSale;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryFlockWeightReport;
use App\Models\PoultryMedicationRecord;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryVaccinationRecord;
use App\Models\SalesRecord;
use App\Services\FlockActivityReportService;
use App\Services\FlockMetricsAnalysisService;
use App\Services\SalesProfitLossService;
use Carbon\Carbon;

class BatchChatContextService
{
    public function __construct(
        protected FlockMetricsAnalysisService $metricsAnalysis,
        protected SalesProfitLossService $profitLoss,
        protected FlockActivityReportService $activityReport,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Flock $flock): array
    {
        $farmId = (int) $flock->farm_id;
        $snapshot = $this->metricsAnalysis->buildSnapshot($flock);
        $today = Carbon::today()->toDateString();
        $since = Carbon::today()->subDays(13)->toDateString();

        $eggStock = $this->profitLoss->computeEggStock($farmId, (int) $flock->id, $today);

        $flock->loadMissing(['allocations.house', 'poultryType', 'flockStage']);

        $allocations = $flock->allocations->map(fn ($a) => [
            'house' => $a->house?->name,
            'quantity' => (int) ($a->quantity ?? 0),
        ])->values()->all();

        $feedingPending = FeedingBatchScheduleItem::query()
            ->whereHas('batchSchedule', fn ($q) => $q->where('flock_id', $flock->id))
            ->whereDate('feeding_date', '<=', $today)
            ->whereIn('status', ['pending', 'missed', 'overdue'])
            ->count();

        $medVacPending = BatchScheduleItem::query()
            ->whereHas('batchSchedule', fn ($q) => $q->where('flock_id', $flock->id))
            ->whereDate('scheduled_date', '<=', $today)
            ->whereIn('status', ['pending', 'missed', 'overdue'])
            ->count();

        $farm = Farm::find($farmId);
        $activitySummary = [];
        if ($farm) {
            try {
                $report = $this->activityReport->report(
                    $farm,
                    $flock,
                    $since,
                    $today,
                    null,
                    null,
                    1,
                    1
                );
                $activitySummary = $report['summary'] ?? [];
            } catch (\Throwable) {
                $activitySummary = [];
            }
        }

        return [
            'as_of' => $today,
            'snapshot' => $snapshot,
            'egg_stock' => $eggStock,
            'allocations' => $allocations,
            'schedule_health' => [
                'feeding_pending_or_overdue' => $feedingPending,
                'medication_vaccination_pending_or_overdue' => $medVacPending,
            ],
            'activity_summary_last_14_days' => $activitySummary,
            'recent_note' => 'The recent.* arrays only cover ~14 days and are row-capped. For any month or custom range, call list_recent_records with date_from and date_to.',
            'recent' => [
                'daily_records' => $this->mapDaily($flock, $since),
                'mortality' => $this->mapMortality($flock, $since),
                'eggs' => $this->mapEggs($flock, $since),
                'weights' => $this->mapWeights($flock, $since),
                'feed_usages' => $this->mapFeed($flock, $since),
                'medications' => $this->mapMeds($flock, $since),
                'vaccinations' => $this->mapVax($flock, $since),
                'expenditures' => $this->mapExpenditures($flock, $since),
                'bird_sales' => $this->mapBirdSales($flock, $since),
                'product_sales' => $this->mapProductSales($flock, $since),
            ],
            'capabilities' => [
                'can_answer' => [
                    'stock', 'performance', 'mortality', 'eggs', 'feed', 'schedules',
                    'costs', 'sales', 'profit_loss', 'allocations',
                ],
                'can_create_with_confirm' => [
                    'daily_record', 'mortality_report', 'weight_report', 'egg_report',
                    'feed_usage', 'medication_record', 'vaccination_record',
                    'expenditure', 'flock_sale', 'product_sale',
                ],
                'cannot' => ['delete_records', 'close_batch', 'transfer_birds', 'edit_flock_identity'],
            ],
        ];
    }

    public function systemPrompt(Flock $flock): string
    {
        $name = $flock->name ?: 'this flock';
        $batch = $flock->batch_number ?: (string) $flock->id;

        return 'You are the Batch Assistant for Farm Central, helping a farm manager with ONE poultry batch/flock only: '
            ."\"{$name}\" (batch {$batch}, flock_id={$flock->id}). "
            .'Use the provided batch context and tools for facts. Never invent numbers. '
            .'Refuse questions about other flocks or farm-wide topics outside this batch. '
            .'IMPORTANT: The embedded "recent" context only covers about the last 14 days and a small row cap. '
            .'For questions about a full month, last month, a custom date range, or "all" records (sales, eggs, costs, etc.), '
            .'you MUST call list_recent_records with date_from and date_to (YYYY-MM-DD) and a high enough limit. '
            .'Do not claim there were no records on earlier dates unless that tool returns none for that range. '
            .'Prefer the tool summary totals when answering period totals. '
            .'For writes (creating records), call the appropriate tool; the UI will ask the user to Confirm before execution. '
            .'Explain results clearly; when discussing eggs, you may mention crates of 30 eggs. '
            .'Be concise and practical for tropical poultry farms.';
    }

    /**
     * Query flock records for a date range (used by Batch AI tools).
     *
     * @return array{
     *   type: string,
     *   date_from: ?string,
     *   date_to: ?string,
     *   returned: int,
     *   truncated: bool,
     *   summary: array<string, mixed>,
     *   rows: list<array<string, mixed>>
     * }
     */
    public function listRecords(
        Flock $flock,
        string $type,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 100
    ): array {
        $limit = min(300, max(1, $limit));
        $fetch = $limit + 1;

        $rows = match ($type) {
            'daily' => $this->mapDaily($flock, $dateFrom, $dateTo, $fetch),
            'mortality' => $this->mapMortality($flock, $dateFrom, $dateTo, $fetch),
            'eggs' => $this->mapEggs($flock, $dateFrom, $dateTo, $fetch),
            'weights' => $this->mapWeights($flock, $dateFrom, $dateTo, $fetch),
            'feed' => $this->mapFeed($flock, $dateFrom, $dateTo, $fetch),
            'medications' => $this->mapMeds($flock, $dateFrom, $dateTo, $fetch),
            'vaccinations' => $this->mapVax($flock, $dateFrom, $dateTo, $fetch),
            'expenditures' => $this->mapExpenditures($flock, $dateFrom, $dateTo, $fetch),
            'bird_sales' => $this->mapBirdSales($flock, $dateFrom, $dateTo, $fetch),
            'product_sales' => $this->mapProductSales($flock, $dateFrom, $dateTo, $fetch),
            default => [],
        };

        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'type' => $type,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'returned' => count($rows),
            'truncated' => $truncated,
            'summary' => $this->summarizeRows($type, $rows),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarizeRows(string $type, array $rows): array
    {
        return match ($type) {
            'product_sales' => [
                'count' => count($rows),
                'total_quantity' => round(array_sum(array_map(fn ($r) => (float) ($r['quantity'] ?? 0), $rows)), 2),
                'total_amount' => round(array_sum(array_map(fn ($r) => (float) ($r['total_amount'] ?? 0), $rows)), 2),
                'by_type' => $this->groupProductSales($rows),
            ],
            'bird_sales' => [
                'count' => count($rows),
                'birds_sold' => (int) array_sum(array_map(fn ($r) => (int) ($r['quantity'] ?? 0), $rows)),
                'total_amount' => round(array_sum(array_map(fn ($r) => (float) ($r['total_amount'] ?? 0), $rows)), 2),
            ],
            'eggs' => [
                'count' => count($rows),
                'eggs_collected' => (int) array_sum(array_map(fn ($r) => (int) ($r['eggs_collected'] ?? 0), $rows)),
                'eggs_broken' => (int) array_sum(array_map(fn ($r) => (int) ($r['eggs_broken'] ?? 0), $rows)),
            ],
            'expenditures' => [
                'count' => count($rows),
                'total_amount' => round(array_sum(array_map(fn ($r) => (float) ($r['amount'] ?? 0), $rows)), 2),
            ],
            'feed' => [
                'count' => count($rows),
                'total_kg' => round(array_sum(array_map(fn ($r) => (float) ($r['kg'] ?? 0), $rows)), 2),
            ],
            'mortality' => [
                'count' => count($rows),
                'total_mortality' => (int) array_sum(array_map(fn ($r) => (int) ($r['count'] ?? 0), $rows)),
            ],
            default => ['count' => count($rows)],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{quantity: float, amount: float, count: int}>
     */
    private function groupProductSales(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? 'unknown');
            if (! isset($out[$type])) {
                $out[$type] = ['quantity' => 0.0, 'amount' => 0.0, 'count' => 0];
            }
            $out[$type]['quantity'] += (float) ($row['quantity'] ?? 0);
            $out[$type]['amount'] += (float) ($row['total_amount'] ?? 0);
            $out[$type]['count']++;
        }
        foreach ($out as $type => $vals) {
            $out[$type]['quantity'] = round($vals['quantity'], 2);
            $out[$type]['amount'] = round($vals['amount'], 2);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapDaily(Flock $flock, ?string $since = null, ?string $until = null, int $limit = 14): array
    {
        $q = FlockDailyRecord::where('flock_id', $flock->id);
        $this->applyDateBounds($q, 'date', $since, $until);

        return $q->orderByDesc('date')
            ->limit($limit)
            ->get(['date', 'mortality', 'mortality_count', 'culls', 'culling_count', 'eggs_collected', 'eggs_broken', 'feed_consumed_kg', 'feed_consumption_kg', 'water_consumed_liters', 'notes'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'mortality' => (int) ($r->mortality_count ?? $r->mortality ?? 0),
                'culls' => (int) ($r->culling_count ?? $r->culls ?? 0),
                'eggs_collected' => (int) ($r->eggs_collected ?? 0),
                'eggs_broken' => (int) ($r->eggs_broken ?? 0),
                'feed_kg' => (float) ($r->feed_consumption_kg ?? $r->feed_consumed_kg ?? 0),
                'water_l' => (float) ($r->water_consumed_liters ?? 0),
                'notes' => $r->notes,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapMortality(Flock $flock, ?string $since = null, ?string $until = null, int $limit = 14): array
    {
        $q = PoultryMortalityReport::where('flock_id', $flock->id);
        $this->applyDateBounds($q, 'date', $since, $until);

        return $q->orderByDesc('date')
            ->limit($limit)
            ->get(['date', 'mortality_count', 'notes'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'count' => (int) $r->mortality_count,
                'notes' => $r->notes,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapEggs(Flock $flock, ?string $since = null, ?string $until = null, int $limit = 14): array
    {
        $q = PoultryFlockEggReport::where('flock_id', $flock->id);
        $this->applyDateBounds($q, 'date', $since, $until);

        return $q->orderByDesc('date')
            ->limit($limit)
            ->get(['date', 'eggs_collected', 'eggs_broken', 'production_percentage', 'bird_count'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'eggs_collected' => (int) $r->eggs_collected,
                'eggs_broken' => (int) ($r->eggs_broken ?? 0),
                'production_percentage' => (float) ($r->production_percentage ?? 0),
                'bird_count' => (int) ($r->bird_count ?? 0),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapWeights(Flock $flock, ?string $since = null, ?string $until = null, int $limit = 10): array
    {
        $q = PoultryFlockWeightReport::where('flock_id', $flock->id);
        $this->applyDateBounds($q, 'report_date', $since, $until);

        return $q->orderByDesc('report_date')
            ->limit($limit)
            ->get(['report_date', 'average_weight', 'sample_size'])
            ->map(fn ($r) => [
                'date' => optional($r->report_date)?->toDateString() ?? (string) $r->report_date,
                'average_weight' => (float) $r->average_weight,
                'sample_size' => (int) ($r->sample_size ?? 0),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapFeed(Flock $flock, ?string $since = null, ?string $until = null, int $limit = 14): array
    {
        $q = PoultryFeedUsage::where('flock_id', $flock->id);
        $this->applyDateBounds($q, 'usage_date', $since, $until);

        return $q->orderByDesc('usage_date')
            ->limit($limit)
            ->get(['usage_date', 'quantity', 'poultry_feed_type_id', 'unit_cost'])
            ->map(fn ($r) => [
                'date' => optional($r->usage_date)?->toDateString() ?? (string) $r->usage_date,
                'kg' => (float) $r->quantity,
                'poultry_feed_type_id' => (int) ($r->poultry_feed_type_id ?? 0),
                'unit_cost' => (float) ($r->unit_cost ?? 0),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapMeds(Flock $flock, ?string $since = null, ?string $until = null, int $limit = 10): array
    {
        $q = PoultryMedicationRecord::where('flock_id', $flock->id);
        $this->applyDateBounds($q, 'date', $since, $until);

        return $q->orderByDesc('date')
            ->limit($limit)
            ->get(['date', 'dosage', 'notes'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'dosage' => $r->dosage,
                'notes' => $r->notes,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapVax(Flock $flock, ?string $since = null, ?string $until = null, int $limit = 10): array
    {
        $q = PoultryVaccinationRecord::where('flock_id', $flock->id);
        $this->applyDateBounds($q, 'date', $since, $until);

        return $q->orderByDesc('date')
            ->limit($limit)
            ->get(['date', 'dosage', 'notes'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'dosage' => $r->dosage,
                'notes' => $r->notes,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapExpenditures(Flock $flock, ?string $since = null, ?string $until = null, int $limit = 14): array
    {
        $q = FlockExpenditure::where('flock_id', $flock->id);
        $this->applyDateBounds($q, 'date', $since, $until);

        return $q->orderByDesc('date')
            ->limit($limit)
            ->get(['date', 'category', 'amount', 'description'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'category' => $r->category,
                'amount' => (float) $r->amount,
                'description' => $r->description,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapBirdSales(Flock $flock, ?string $since = null, ?string $until = null, int $limit = 14): array
    {
        $q = FlockSale::where('flock_id', $flock->id);
        $this->applyDateBounds($q, 'date', $since, $until);

        return $q->orderByDesc('date')
            ->limit($limit)
            ->get(['date', 'quantity', 'unit_price', 'total_amount', 'customer_name'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'quantity' => (int) $r->quantity,
                'unit_price' => (float) $r->unit_price,
                'total_amount' => (float) $r->total_amount,
                'customer_name' => $r->customer_name,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapProductSales(Flock $flock, ?string $since = null, ?string $until = null, int $limit = 14): array
    {
        $q = SalesRecord::where('flock_id', $flock->id);
        $this->applyDateBounds($q, 'date', $since, $until);

        return $q->orderByDesc('date')
            ->limit($limit)
            ->get(['date', 'type', 'quantity', 'unit_price', 'total_amount'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'type' => $r->type,
                'quantity' => (float) $r->quantity,
                'unit_price' => (float) $r->unit_price,
                'total_amount' => (float) $r->total_amount,
            ])->values()->all();
    }

    private function applyDateBounds($query, string $column, ?string $since, ?string $until): void
    {
        if ($since) {
            $query->whereDate($column, '>=', $since);
        }
        if ($until) {
            $query->whereDate($column, '<=', $until);
        }
    }
}
