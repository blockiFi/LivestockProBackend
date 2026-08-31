<?php

namespace App\Services;

use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryMortalityReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FlockMetricsAnalysisService
{
    public function __construct(
        protected LlmService $llm,
        protected SalesProfitLossService $profitLossService,
        protected FarmAlertService $alertService,
        protected FlockFcrService $flockFcrService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(Flock $flock): array
    {
        $flock->loadMissing(['poultryType', 'flockStage']);

        $farmId = (int) $flock->farm_id;
        $initialBirds = max(0, (int) $flock->quantity);
        $birdsRemaining = max(0, (int) $flock->actual_quantity);

        $totalMortality = (int) PoultryMortalityReport::where('flock_id', $flock->id)
            ->where('farm_id', $farmId)
            ->sum('mortality_count');
        $dailyMortality = (int) FlockDailyRecord::where('flock_id', $flock->id)
            ->where('farm_id', $farmId)
            ->sum('mortality');
        $totalMortality = max($totalMortality, $dailyMortality);

        $mortalityRate = $initialBirds > 0 ? round(($totalMortality / $initialBirds) * 100, 2) : 0.0;
        $survivalRate = round(max(0, 100 - $mortalityRate), 2);

        $arrival = Carbon::parse($flock->arrival_date)->startOfDay();
        $endDate = $flock->actual_end_date
            ? Carbon::parse($flock->actual_end_date)->startOfDay()
            : Carbon::today();
        $daysInFlock = max(1, $arrival->diffInDays($endDate) + 1);
        $currentAge = max(0, (int) $flock->arrival_age_days) + max(0, $arrival->diffInDays(Carbon::today()));

        $feedKg = (float) PoultryFeedUsage::where('farm_id', $farmId)
            ->where('flock_id', $flock->id)
            ->sum('quantity');
        if ($feedKg <= 0) {
            $feedKg = (float) FlockDailyRecord::where('farm_id', $farmId)
                ->where('flock_id', $flock->id)
                ->sum('feed_consumed_kg');
        }

        $weights = FlockDailyRecord::where('farm_id', $farmId)
            ->where('flock_id', $flock->id)
            ->whereNotNull('avg_weight_grams')
            ->where('avg_weight_grams', '>', 0)
            ->selectRaw('MAX(avg_weight_grams) as max_w')
            ->first();

        $weightReports = $flock->weightReports()
            ->orderBy('report_date')
            ->get(['average_weight', 'report_date']);

        $maxWeight = $weights ? (float) $weights->max_w : 0.0;
        if ($weightReports->isNotEmpty()) {
            $maxWeight = max(
                $maxWeight,
                FlockFcrService::weightReportKgToGrams((float) $weightReports->max('average_weight'))
            );
        }

        $fcr = $this->flockFcrService->compute($flock);

        $firstWeight = $weightReports->first();
        $lastWeight = $weightReports->last();
        $weightGainRate = 0.0;
        if ($firstWeight && $lastWeight) {
            $days = max(1, Carbon::parse($firstWeight->report_date)->diffInDays(Carbon::parse($lastWeight->report_date)));
            $weightGainRate = round(((float) $lastWeight->average_weight - (float) $firstWeight->average_weight) / $days, 2);
        }

        $eggProductionRate = round((float) ($flock->eggReports()->avg('production_percentage') ?? 0), 2);
        $totalEggs = (int) $flock->eggReports()->sum('eggs_collected');
        if ($totalEggs <= 0) {
            $totalEggs = (int) FlockDailyRecord::where('flock_id', $flock->id)->sum('eggs_collected');
        }

        $financial = $this->profitLossService->flockSummary($farmId, (int) $flock->id);

        $recentMortality = $this->recentMortalitySeries($farmId, (int) $flock->id, 7);
        $recentFeed = $this->recentFeedSeries($farmId, (int) $flock->id, 7);

        $alerts = $this->alertService->forFarm($flock->farm, $flock);
        $alertCounts = $alerts['counts'] ?? ['critical' => 0, 'warning' => 0, 'info' => 0];

        return [
            'flock' => [
                'id' => $flock->id,
                'name' => $flock->name,
                'batch_number' => $flock->batch_number,
                'breed' => $flock->breed,
                'poultry_type' => $flock->poultryType?->name,
                'stage' => $flock->flockStage?->name,
                'status' => $flock->status,
                'age_days' => $currentAge,
                'days_in_flock' => $daysInFlock,
                'initial_birds' => $initialBirds,
                'birds_remaining' => $birdsRemaining,
            ],
            'performance' => [
                'mortality_rate_percent' => $mortalityRate,
                'survival_rate_percent' => $survivalRate,
                'total_mortality' => $totalMortality,
                'feed_kg' => round($feedKg, 2),
                'feed_per_bird_kg' => $birdsRemaining > 0 ? round($feedKg / $birdsRemaining, 2) : 0,
                'feed_conversion_ratio' => $fcr,
                'weight_gain_rate_g_per_day' => $weightGainRate,
                'latest_weight_g' => $maxWeight > 0 ? round($maxWeight, 2) : null,
                'egg_production_rate_percent' => $eggProductionRate,
                'total_eggs' => $totalEggs,
            ],
            'financial' => [
                'total_revenue' => $financial['total_revenue'] ?? 0,
                'total_cost' => $financial['total_cost'] ?? 0,
                'net_profit' => $financial['net_profit'] ?? 0,
                'margin_percent' => $financial['margin_percent'] ?? 0,
                'birds_sold' => $financial['birds_sold'] ?? 0,
                'cost_by_category' => $financial['cost_by_category'] ?? [],
            ],
            'schedule_health' => [
                'alert_counts' => $alertCounts,
                'top_alerts' => collect($alerts['items'] ?? [])->take(5)->values()->all(),
            ],
            'recent_trends' => [
                'mortality_last_7_days' => $recentMortality,
                'feed_last_7_days' => $recentFeed,
            ],
            'records_summary' => [
                'daily_records' => $flock->dailyRecords()->count(),
                'mortality_reports' => $flock->mortalityReports()->count(),
                'weight_reports' => $flock->weightReports()->count(),
                'egg_reports' => $flock->eggReports()->count(),
                'feed_usages' => $flock->poultryFeedUsages()->count(),
            ],
        ];
    }

    /**
     * @return array{insights: ?array<string, mixed>, analysis: ?string}
     */
    public function generateInsights(Flock $flock): array
    {
        $snapshot = $this->buildSnapshot($flock);

        $system = 'You are an expert poultry farm performance analyst specializing in broilers and layers in tropical climates. '
            . 'Given flock performance data, provide actionable insights for a farm manager. '
            . 'Respond ONLY with valid JSON (no markdown fences) using this schema: '
            . '{"executive_summary":"string","performance_score":"good|fair|poor","strengths":["string"],"risks":["string"],'
            . '"recommendations":[{"priority":"high|medium|low","action":"string","reason":"string"}],'
            . '"benchmark_comparison":"string"}. '
            . 'Be specific, practical, and concise. If data is sparse, say so and recommend what records to capture.';

        $user = "Analyse this flock performance snapshot and return JSON only:\n"
            . json_encode($snapshot, JSON_PRETTY_PRINT);

        $raw = $this->llm->chat($system, $user);
        if (!$raw) {
            return ['insights' => null, 'analysis' => null];
        }

        $parsed = $this->parseInsightsJson($raw);
        if ($parsed) {
            return ['insights' => $parsed, 'analysis' => null];
        }

        Log::warning('FlockMetricsAnalysisService: LLM returned non-JSON insights', [
            'flock_id' => $flock->id,
        ]);

        return ['insights' => null, 'analysis' => trim($raw)];
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    private function recentMortalitySeries(int $farmId, int $flockId, int $days): array
    {
        $start = Carbon::today()->subDays($days - 1)->toDateString();
        $rows = PoultryMortalityReport::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->whereDate('date', '>=', $start)
            ->selectRaw('DATE(date) as d, COALESCE(SUM(mortality_count), 0) as mortality')
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => Carbon::parse($row->d)->toDateString(),
            'count' => (int) $row->mortality,
        ])->values()->all();
    }

    /**
     * @return list<array{date: string, kg: float}>
     */
    private function recentFeedSeries(int $farmId, int $flockId, int $days): array
    {
        $start = Carbon::today()->subDays($days - 1)->toDateString();
        $rows = PoultryFeedUsage::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->whereDate('usage_date', '>=', $start)
            ->selectRaw('DATE(usage_date) as d, COALESCE(SUM(quantity), 0) as feed_kg')
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => Carbon::parse($row->d)->toDateString(),
            'kg' => round((float) $row->feed_kg, 2),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseInsightsJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return null;
        }

        $score = strtolower((string) ($decoded['performance_score'] ?? 'fair'));
        if (!in_array($score, ['good', 'fair', 'poor'], true)) {
            $score = 'fair';
        }

        $recommendations = collect($decoded['recommendations'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $priority = strtolower((string) ($item['priority'] ?? 'medium'));
                if (!in_array($priority, ['high', 'medium', 'low'], true)) {
                    $priority = 'medium';
                }

                return [
                    'priority' => $priority,
                    'action' => (string) ($item['action'] ?? ''),
                    'reason' => (string) ($item['reason'] ?? ''),
                ];
            })
            ->filter(fn (array $item) => $item['action'] !== '')
            ->values()
            ->all();

        return [
            'executive_summary' => (string) ($decoded['executive_summary'] ?? ''),
            'performance_score' => $score,
            'strengths' => array_values(array_filter((array) ($decoded['strengths'] ?? []), 'is_string')),
            'risks' => array_values(array_filter((array) ($decoded['risks'] ?? []), 'is_string')),
            'recommendations' => $recommendations,
            'benchmark_comparison' => isset($decoded['benchmark_comparison'])
                ? (string) $decoded['benchmark_comparison']
                : null,
        ];
    }
}
