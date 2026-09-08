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
            .'For writes (creating records), call the appropriate tool; the UI will ask the user to Confirm before execution. '
            .'Explain results clearly; when discussing eggs, you may mention crates of 30 eggs. '
            .'Be concise and practical for tropical poultry farms.';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapDaily(Flock $flock, string $since): array
    {
        return FlockDailyRecord::where('flock_id', $flock->id)
            ->whereDate('date', '>=', $since)
            ->orderByDesc('date')
            ->limit(14)
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
    private function mapMortality(Flock $flock, string $since): array
    {
        return PoultryMortalityReport::where('flock_id', $flock->id)
            ->whereDate('date', '>=', $since)
            ->orderByDesc('date')
            ->limit(14)
            ->get(['date', 'mortality_count', 'notes'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'count' => (int) $r->mortality_count,
                'notes' => $r->notes,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapEggs(Flock $flock, string $since): array
    {
        return PoultryFlockEggReport::where('flock_id', $flock->id)
            ->whereDate('date', '>=', $since)
            ->orderByDesc('date')
            ->limit(14)
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
    private function mapWeights(Flock $flock, string $since): array
    {
        return PoultryFlockWeightReport::where('flock_id', $flock->id)
            ->whereDate('report_date', '>=', $since)
            ->orderByDesc('report_date')
            ->limit(10)
            ->get(['report_date', 'average_weight', 'sample_size'])
            ->map(fn ($r) => [
                'date' => optional($r->report_date)?->toDateString() ?? (string) $r->report_date,
                'average_weight' => (float) $r->average_weight,
                'sample_size' => (int) ($r->sample_size ?? 0),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapFeed(Flock $flock, string $since): array
    {
        return PoultryFeedUsage::where('flock_id', $flock->id)
            ->whereDate('usage_date', '>=', $since)
            ->orderByDesc('usage_date')
            ->limit(14)
            ->get(['usage_date', 'quantity', 'notes'])
            ->map(fn ($r) => [
                'date' => optional($r->usage_date)?->toDateString() ?? (string) $r->usage_date,
                'kg' => (float) $r->quantity,
                'notes' => $r->notes,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapMeds(Flock $flock, string $since): array
    {
        return PoultryMedicationRecord::where('flock_id', $flock->id)
            ->whereDate('date', '>=', $since)
            ->orderByDesc('date')
            ->limit(10)
            ->get(['date', 'dosage', 'notes'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'dosage' => $r->dosage,
                'notes' => $r->notes,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapVax(Flock $flock, string $since): array
    {
        return PoultryVaccinationRecord::where('flock_id', $flock->id)
            ->whereDate('date', '>=', $since)
            ->orderByDesc('date')
            ->limit(10)
            ->get(['date', 'dosage', 'notes'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'dosage' => $r->dosage,
                'notes' => $r->notes,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapExpenditures(Flock $flock, string $since): array
    {
        return FlockExpenditure::where('flock_id', $flock->id)
            ->whereDate('date', '>=', $since)
            ->orderByDesc('date')
            ->limit(14)
            ->get(['date', 'category', 'amount', 'description'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'category' => $r->category,
                'amount' => (float) $r->amount,
                'description' => $r->description,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapBirdSales(Flock $flock, string $since): array
    {
        return FlockSale::where('flock_id', $flock->id)
            ->whereDate('date', '>=', $since)
            ->orderByDesc('date')
            ->limit(14)
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
    private function mapProductSales(Flock $flock, string $since): array
    {
        return SalesRecord::where('flock_id', $flock->id)
            ->whereDate('date', '>=', $since)
            ->orderByDesc('date')
            ->limit(14)
            ->get(['date', 'type', 'quantity', 'unit_price', 'total_amount'])
            ->map(fn ($r) => [
                'date' => optional($r->date)?->toDateString() ?? (string) $r->date,
                'type' => $r->type,
                'quantity' => (float) $r->quantity,
                'unit_price' => (float) $r->unit_price,
                'total_amount' => (float) $r->total_amount,
            ])->values()->all();
    }
}
