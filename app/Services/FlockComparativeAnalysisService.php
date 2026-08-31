<?php

namespace App\Services;

use App\Models\Flock;
use App\Models\FlockComparativeReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlockComparativeAnalysisService
{
    private const COMPLETED_STATUSES = ['sold', 'culled', 'completed'];

    private const LOWER_IS_BETTER = [
        'mortality_rate_percent',
        'feed_per_bird_kg',
        'feed_conversion_ratio',
        'cost_per_bird',
    ];

    public function __construct(
        protected FlockMetricsAnalysisService $metricsService,
        protected LlmService $llm
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrGenerate(Flock $flock, ?int $userId = null, bool $force = false): array
    {
        $flock->loadMissing(['poultryType']);
        $peerIds = $this->loadPeerFlocks($flock)->pluck('id')->all();
        $fingerprint = $this->computeFingerprint($flock, $peerIds);

        if (!$force) {
            $cached = FlockComparativeReport::where('farm_id', $flock->farm_id)
                ->where('flock_id', $flock->id)
                ->first();

            if ($cached && $cached->data_fingerprint === $fingerprint) {
                $payload = $cached->report_payload;
                $payload['cached'] = true;
                $payload['generated_at'] = $cached->generated_at?->toIso8601String();
                $payload['ai_insights'] = $cached->ai_insights;

                return $payload;
            }
        }

        $report = $this->buildReport($flock, $peerIds);
        $aiInsights = $this->generateComparativeInsights($report);

        FlockComparativeReport::updateOrCreate(
            [
                'farm_id' => $flock->farm_id,
                'flock_id' => $flock->id,
            ],
            [
                'poultry_type_id' => $flock->poultry_type_id,
                'data_fingerprint' => $fingerprint,
                'report_payload' => $report,
                'ai_insights' => $aiInsights,
                'generated_by' => $userId,
                'generated_at' => now(),
            ]
        );

        $report['cached'] = false;
        $report['generated_at'] = now()->toIso8601String();
        $report['ai_insights'] = $aiInsights;

        return $report;
    }

    /**
     * @return Collection<int, Flock>
     */
    private function loadPeerFlocks(Flock $flock): Collection
    {
        return Flock::where('farm_id', $flock->farm_id)
            ->where('poultry_type_id', $flock->poultry_type_id)
            ->where('id', '!=', $flock->id)
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->orderByDesc('actual_end_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param list<int> $peerIds
     */
    private function computeFingerprint(Flock $flock, array $peerIds): string
    {
        $allIds = array_merge([$flock->id], $peerIds);
        $parts = [];

        foreach ($allIds as $flockId) {
            $parts[] = $this->flockRecordSignature((int) $flockId);
        }

        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    private function flockRecordSignature(int $flockId): string
    {
        $tables = [
            ['flock_daily_records', 'flock_id'],
            ['poultry_flock_weight_reports', 'flock_id'],
            ['poultry_feed_usages', 'flock_id'],
            ['poultry_mortality_reports', 'flock_id'],
            ['poultry_flock_egg_reports', 'flock_id'],
            ['flock_expenditures', 'flock_id'],
            ['flock_sales', 'flock_id'],
        ];

        $segments = ["flock:{$flockId}"];

        foreach ($tables as [$table, $column]) {
            $row = DB::table($table)
                ->where($column, $flockId)
                ->selectRaw('COUNT(*) as cnt, COALESCE(MAX(updated_at), "") as max_u')
                ->first();

            $segments[] = "{$table}:{$row->cnt}:{$row->max_u}";
        }

        $flockRow = Flock::whereKey($flockId)->first(['updated_at', 'status', 'quantity']);
        if ($flockRow) {
            $segments[] = "meta:{$flockRow->status}:{$flockRow->quantity}:{$flockRow->updated_at}";
        }

        return implode(';', $segments);
    }

    /**
     * @param list<int> $peerIds
     * @return array<string, mixed>
     */
    private function buildReport(Flock $flock, array $peerIds): array
    {
        $peers = Flock::whereIn('id', $peerIds)->get();
        $targetRow = $this->buildFlockRow($flock);
        $peerRows = $peers->map(fn (Flock $peer) => $this->buildFlockRow($peer))->values()->all();

        $poultryType = $flock->poultryType?->name ?? 'Unknown';
        $poultryKind = $this->resolvePoultryKind($poultryType);
        $metricKeys = $this->metricKeysForKind($poultryKind);

        $aggregates = $this->computeAggregates($targetRow['metrics'], $peerRows, $metricKeys);
        $highlights = $this->computeHighlights($aggregates, $metricKeys);

        return [
            'cached' => false,
            'peer_count' => count($peerRows),
            'poultry_type' => $poultryType,
            'poultry_kind' => $poultryKind,
            'target_flock' => $targetRow,
            'peers' => $peerRows,
            'aggregates' => $aggregates,
            'highlights' => $highlights,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFlockRow(Flock $flock): array
    {
        $snapshot = $this->metricsService->buildSnapshot($flock);
        $flockData = $snapshot['flock'];
        $performance = $snapshot['performance'];
        $financial = $snapshot['financial'];
        $initialBirds = max(1, (int) ($flockData['initial_birds'] ?? 1));

        return [
            'id' => $flock->id,
            'name' => $flockData['name'],
            'batch_number' => $flockData['batch_number'],
            'status' => $flockData['status'],
            'breed' => $flockData['breed'],
            'metrics' => [
                'mortality_rate_percent' => $performance['mortality_rate_percent'],
                'survival_rate_percent' => $performance['survival_rate_percent'],
                'days_in_flock' => $flockData['days_in_flock'],
                'age_days' => $flockData['age_days'],
                'feed_kg' => $performance['feed_kg'],
                'feed_per_bird_kg' => $performance['feed_per_bird_kg'],
                'feed_conversion_ratio' => $performance['feed_conversion_ratio'],
                'weight_gain_rate_g_per_day' => $performance['weight_gain_rate_g_per_day'],
                'latest_weight_g' => $performance['latest_weight_g'],
                'egg_production_rate_percent' => $performance['egg_production_rate_percent'],
                'total_eggs' => $performance['total_eggs'],
                'total_revenue' => $financial['total_revenue'],
                'total_cost' => $financial['total_cost'],
                'net_profit' => $financial['net_profit'],
                'margin_percent' => $financial['margin_percent'],
                'cost_per_bird' => round(((float) $financial['total_cost']) / $initialBirds, 2),
                'birds_sold' => $financial['birds_sold'],
            ],
        ];
    }

    private function resolvePoultryKind(string $typeName): string
    {
        $name = strtolower($typeName);
        if (str_contains($name, 'dual')) {
            return 'dual';
        }
        if (str_contains($name, 'layer') || str_contains($name, 'lay')) {
            return 'layer';
        }
        if (str_contains($name, 'broiler') || str_contains($name, 'meat')) {
            return 'broiler';
        }

        return 'other';
    }

    /**
     * @return list<string>
     */
    private function metricKeysForKind(string $kind): array
    {
        $base = [
            'mortality_rate_percent',
            'survival_rate_percent',
            'feed_kg',
            'feed_per_bird_kg',
            'net_profit',
            'margin_percent',
            'cost_per_bird',
            'days_in_flock',
        ];

        if ($kind === 'broiler' || $kind === 'dual') {
            $base = array_merge($base, [
                'feed_conversion_ratio',
                'weight_gain_rate_g_per_day',
                'latest_weight_g',
            ]);
        }

        if ($kind === 'layer' || $kind === 'dual') {
            $base = array_merge($base, [
                'egg_production_rate_percent',
                'total_eggs',
            ]);
        }

        return array_values(array_unique($base));
    }

    /**
     * @param array<string, mixed> $targetMetrics
     * @param list<array<string, mixed>> $peerRows
     * @param list<string> $metricKeys
     * @return array<string, mixed>
     */
    private function computeAggregates(array $targetMetrics, array $peerRows, array $metricKeys): array
    {
        $aggregates = [];

        foreach ($metricKeys as $key) {
            $peerValues = collect($peerRows)
                ->map(fn (array $row) => $row['metrics'][$key] ?? null)
                ->filter(fn ($value) => $value !== null && is_numeric($value))
                ->map(fn ($value) => (float) $value)
                ->values();

            $targetValue = isset($targetMetrics[$key]) && is_numeric($targetMetrics[$key])
                ? (float) $targetMetrics[$key]
                : null;

            if ($peerValues->isEmpty()) {
                $aggregates[$key] = [
                    'target' => $targetValue,
                    'avg' => null,
                    'min' => null,
                    'max' => null,
                    'median' => null,
                    'rank' => null,
                    'percentile' => null,
                    'delta_vs_avg' => null,
                    'peer_count' => 0,
                ];
                continue;
            }

            $sorted = $peerValues->sort()->values();
            $avg = round($sorted->avg(), 2);
            $min = round($sorted->min(), 2);
            $max = round($sorted->max(), 2);
            $median = round($this->median($sorted->all()), 2);

            $rank = null;
            $percentile = null;
            $deltaVsAvg = $targetValue !== null ? round($targetValue - $avg, 2) : null;

            if ($targetValue !== null) {
                $allValues = $peerValues->push($targetValue)->values();
                $lowerIsBetter = in_array($key, self::LOWER_IS_BETTER, true);
                $ranked = $allValues
                    ->sortBy(fn ($v) => $lowerIsBetter ? $v : -$v)
                    ->values()
                    ->all();

                $rank = array_search($targetValue, $ranked, true);
                $rank = $rank !== false ? $rank + 1 : null;
                $total = count($ranked);
                $percentile = $rank !== null && $total > 0
                    ? round((($total - $rank + 1) / $total) * 100, 1)
                    : null;
            }

            $aggregates[$key] = [
                'target' => $targetValue,
                'avg' => $avg,
                'min' => $min,
                'max' => $max,
                'median' => $median,
                'rank' => $rank,
                'percentile' => $percentile,
                'delta_vs_avg' => $deltaVsAvg,
                'peer_count' => $peerValues->count(),
            ];
        }

        return $aggregates;
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        sort($values);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    /**
     * @param array<string, mixed> $aggregates
     * @param list<string> $metricKeys
     * @return array{strengths: list<string>, gaps: list<string>}
     */
    private function computeHighlights(array $aggregates, array $metricKeys): array
    {
        $strengths = [];
        $gaps = [];

        $labels = [
            'mortality_rate_percent' => 'Mortality rate',
            'survival_rate_percent' => 'Survival rate',
            'feed_kg' => 'Total feed',
            'feed_per_bird_kg' => 'Feed per bird',
            'feed_conversion_ratio' => 'FCR',
            'weight_gain_rate_g_per_day' => 'Daily weight gain',
            'latest_weight_g' => 'Latest weight',
            'egg_production_rate_percent' => 'Hen-day production',
            'total_eggs' => 'Total eggs',
            'net_profit' => 'Net profit',
            'margin_percent' => 'Profit margin',
            'cost_per_bird' => 'Cost per bird',
            'days_in_flock' => 'Days in flock',
        ];

        foreach ($metricKeys as $key) {
            $agg = $aggregates[$key] ?? null;
            if (!$agg || $agg['avg'] === null || $agg['target'] === null || (float) $agg['avg'] == 0.0) {
                continue;
            }

            $label = $labels[$key] ?? $key;
            $target = (float) $agg['target'];
            $avg = (float) $agg['avg'];
            $pctDiff = abs($target - $avg) / abs($avg) * 100;

            if ($pctDiff < 10) {
                continue;
            }

            $lowerIsBetter = in_array($key, self::LOWER_IS_BETTER, true);
            $isBetter = $lowerIsBetter ? $target < $avg : $target > $avg;

            if ($isBetter) {
                $strengths[] = "{$label} is " . round($pctDiff, 1) . '% better than completed-batch average';
            } else {
                $gaps[] = "{$label} is " . round($pctDiff, 1) . '% below completed-batch average';
            }
        }

        return [
            'strengths' => array_slice($strengths, 0, 5),
            'gaps' => array_slice($gaps, 0, 5),
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>|null
     */
    private function generateComparativeInsights(array $report): ?array
    {
        if ($report['peer_count'] === 0) {
            return null;
        }

        $system = 'You are an expert poultry farm performance analyst. '
            . 'Given a batch compared against completed peer batches of the same type, '
            . 'provide a concise comparative analysis. '
            . 'Respond ONLY with valid JSON (no markdown fences) using this schema: '
            . '{"executive_summary":"string","peer_ranking_summary":"string",'
            . '"strengths":["string"],"gaps":["string"],'
            . '"recommendations":[{"priority":"high|medium|low","action":"string","reason":"string"}]}. '
            . 'Reference specific metrics and peer averages from the data.';

        $user = "Analyse this comparative batch report and return JSON only:\n"
            . json_encode($report, JSON_PRETTY_PRINT);

        $raw = $this->llm->chat($system, $user);
        if (!$raw) {
            return null;
        }

        return $this->parseComparativeInsightsJson($raw);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseComparativeInsightsJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            Log::warning('FlockComparativeAnalysisService: LLM returned non-JSON insights');

            return null;
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
            'peer_ranking_summary' => (string) ($decoded['peer_ranking_summary'] ?? ''),
            'strengths' => array_values(array_filter((array) ($decoded['strengths'] ?? []), 'is_string')),
            'gaps' => array_values(array_filter((array) ($decoded['gaps'] ?? []), 'is_string')),
            'recommendations' => $recommendations,
        ];
    }
}
