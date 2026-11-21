<?php

namespace App\Services\EggCollection;

use App\Models\Flock;
use App\Models\PoultryFlockEggCollection;
use App\Models\PoultryFlockEggCollectionCell;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryHouseCell;
use App\Services\LlmService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Day-on-day analysis of cell-based egg collection for one flock.
 *
 * A "recorded day" is a day with at least one by-cell collection round for the flock. On a recorded
 * day a cell with no entry counts as 0 eggs, like a blank box on the paper register. A cell is only
 * judged from the first recorded day its row had any entry, so a row that was added later (or a house
 * the flock moved into) doesn't look like it had empty days before.
 *
 * Changes are measured against the previous recorded day (not the calendar day before), so a skipped
 * day doesn't make every cell look like it stopped.
 */
class CellInsightService
{
    public const STATUS_FIRST_EGG = 'first_egg';
    public const STATUS_INCREASE = 'increase';
    public const STATUS_DECREASE = 'decrease';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_STEADY = 'steady';
    public const STATUS_SILENT = 'silent';
    public const STATUS_NO_HISTORY = 'no_history';

    /** How many recorded days back we read for streaks and the trend chart. */
    private const HISTORY_DAYS = 60;

    public function __construct(
        private EggCollectionService $collections,
        private LlmService $llm,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyse(Flock $flock, string $date, int $window = 7, int $silentDays = 3): array
    {
        $date = Carbon::parse($date)->toDateString();
        $window = max(2, min(30, $window));
        $silentDays = max(2, min(30, $silentDays));

        $recordedDates = $this->recordedDates($flock, $date);
        $cells = $this->collections->flockCells($flock);

        $base = [
            'flock_id' => $flock->id,
            'date' => $date,
            'window_days' => $window,
            'silent_days' => $silentDays,
            'recorded_dates' => $recordedDates,
            'has_cells' => $cells->isNotEmpty(),
        ];

        if ($cells->isEmpty() || empty($recordedDates) || $recordedDates[0] !== $date) {
            return $base + [
                'has_data' => false,
                'latest_recorded_date' => $this->latestRecordedDate($flock),
            ];
        }

        $previousDate = $recordedDates[1] ?? null;
        $eggsByCellDate = $this->eggsByCellAndDate($flock, $recordedDates);
        $everBefore = $this->eggsBeforeDate($flock, $date);

        $windowDates = array_slice($recordedDates, 0, $window);
        $rowSince = [];
        foreach ($cells as $cell) {
            $dates = array_keys($eggsByCellDate[$cell->id] ?? []);
            if ($dates) {
                $min = min($dates);
                $rowSince[$cell->row_id] = isset($rowSince[$cell->row_id]) ? min($rowSince[$cell->row_id], $min) : $min;
            }
        }
        $cellResults = [];

        foreach ($cells as $cell) {
            $cellResults[] = $this->analyseCell(
                $cell,
                $rowSince[$cell->row_id] ?? $date,
                $recordedDates,
                $windowDates,
                $previousDate,
                $eggsByCellDate[$cell->id] ?? [],
                (int) ($everBefore[$cell->id] ?? 0),
                $silentDays,
            );
        }

        $cellResults = $this->markRelativePerformance($cellResults);

        $summary = $this->summary($cellResults, $previousDate !== null);
        $entered = fn (?string $d) => $d === null ? null : collect($eggsByCellDate)->filter(fn ($s) => isset($s[$d]))->count();
        $summary['cells_entered'] = $entered($date);
        $summary['previous_cells_entered'] = $entered($previousDate);
        $summary['rounds'] = $this->roundsOn($flock, $date);
        // A day still being collected (e.g. only the morning round in) looks like a big drop.
        $summary['possibly_incomplete'] = $previousDate !== null
            && $summary['previous_cells_entered'] > 0
            && $summary['cells_entered'] < $summary['previous_cells_entered'] * 0.5;

        return $base + [
            'has_data' => true,
            'previous_date' => $previousDate,
            'summary' => $summary,
            'events' => $this->events($cellResults),
            'rows' => $this->rows($cellResults),
            'houses' => $this->grid($cellResults),
            'trend' => $this->trend($cells, $recordedDates, $eggsByCellDate),
        ];
    }

    /**
     * Ask the LLM to read the analysis like a farm manager would. Returns null when AI is unavailable.
     *
     * @param  array<string, mixed>  $analysis  output of analyse()
     * @return array{insights: ?array, text: ?string}|null
     */
    public function aiSummary(Flock $flock, array $analysis): ?array
    {
        if (empty($analysis['has_data'])) {
            return null;
        }

        $labels = fn (array $list, int $max = 25) => array_map(
            fn ($c) => trim($c['label'].' '.($c['detail'] ?? '')),
            array_slice($list, 0, $max)
        );

        $events = $analysis['events'];
        $payload = [
            'flock' => $flock->name ?? $flock->batch_number,
            'date' => $analysis['date'],
            'compared_with' => $analysis['previous_date'],
            'summary' => $analysis['summary'],
            'rows' => array_map(fn ($r) => array_intersect_key($r, array_flip([
                'house_name', 'row_code', 'cells', 'birds', 'eggs', 'previous_eggs', 'change', 'lay_rate', 'cells_laying',
            ])), $analysis['rows']),
            'trend_last_days' => array_slice($analysis['trend'], -14),
            'first_egg_cells' => $labels($events['first_egg']),
            'increased_cells' => $labels($events['increase']),
            'decreased_cells' => $labels($events['decrease']),
            'stopped_cells' => $labels($events['stopped']),
            'silent_cells' => $labels($events['silent']),
            'never_laid_cells' => $labels($events['never_laid']),
            'over_capacity_cells' => $labels($events['over_capacity']),
            'high_broken_cells' => $labels($events['high_broken']),
            'top_cells' => $labels($events['top']),
            'weak_cells' => $labels($events['weak']),
        ];

        $incomplete = ! empty($analysis['summary']['possibly_incomplete'])
            ? ' IMPORTANT: far fewer cells were entered today than on the previous day, so today\'s collection is probably '
                .'not finished yet (more rounds to come) or cells were skipped. Say this first and treat drops with caution.'
            : '';

        $system = 'You are a poultry layer-farm production analyst. Farms keep birds in cages: each row has a letter '
            .'(A, B, C...) and each cell in a row is numbered (A1, A2...). Each cell holds a few birds (often 3 or 4), '
            .'so a cell can lay at most one egg per bird per day. You get a day\'s cell-by-cell egg collection compared '
            .'with the previous recorded day and the recent window. Explain what changed and what the farm manager should '
            .'check, in plain words for a farm worker. Point to specific cells and rows. Consider causes such as birds '
            .'coming into lay, moulting, illness, a dead or missing bird, egg eating, broken feeders or drinkers, heat, '
            .'recording mistakes (eggs entered in the wrong cell, a cell missed), and cells with more eggs than birds. '
            .'Do not invent numbers that are not in the data. '
            .'Respond ONLY with valid JSON (no markdown fences) using this schema: '
            .'{"headline":"string","summary":"string","positives":["string"],"concerns":["string"],'
            .'"actions":[{"priority":"high|medium|low","action":"string","cells":["A1"],"reason":"string"}],'
            .'"watch_cells":[{"cell":"A1","reason":"string"}]}. Keep each string short.'.$incomplete;

        $raw = $this->llm->chat($system, "Cell egg collection analysis:\n".json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if (! $raw) {
            Log::info('CellInsightService: AI summary unavailable', ['flock_id' => $flock->id, 'error' => $this->llm->getLastError()]);

            return null;
        }

        $clean = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw)));
        $parsed = json_decode($clean, true);

        return is_array($parsed) && isset($parsed['summary'])
            ? ['insights' => $parsed, 'text' => null]
            : ['insights' => null, 'text' => trim($raw)];
    }

    /** Recorded days (with a by-cell round) on or before $date, newest first. */
    private function recordedDates(Flock $flock, string $date): array
    {
        return PoultryFlockEggReport::query()
            ->where('flock_id', $flock->id)
            ->whereDate('date', '<=', $date)
            ->whereHas('collections', fn ($q) => $q->where('entry_mode', PoultryFlockEggCollection::MODE_CELL))
            ->orderByDesc('date')
            ->limit(self::HISTORY_DAYS)
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    private function roundsOn(Flock $flock, string $date): int
    {
        return PoultryFlockEggCollection::query()
            ->whereHas('report', fn ($q) => $q->where('flock_id', $flock->id)->whereDate('date', $date))
            ->count();
    }

    private function latestRecordedDate(Flock $flock): ?string
    {
        $d = PoultryFlockEggReport::query()
            ->where('flock_id', $flock->id)
            ->whereHas('collections', fn ($q) => $q->where('entry_mode', PoultryFlockEggCollection::MODE_CELL))
            ->max('date');

        return $d ? Carbon::parse($d)->toDateString() : null;
    }

    /** @return array<int, array<string, array{eggs: int, broken: int}>> cell_id => date => totals */
    private function eggsByCellAndDate(Flock $flock, array $dates): array
    {
        $oldest = end($dates);
        $newest = $dates[0];

        $rows = $this->cellRowsQuery($flock)
            ->whereDate('r.date', '>=', $oldest)
            ->whereDate('r.date', '<=', $newest)
            ->groupBy('poultry_flock_egg_collection_cells.cell_id', 'r.date')
            ->selectRaw('poultry_flock_egg_collection_cells.cell_id as cell_id, r.date as d,
                SUM(poultry_flock_egg_collection_cells.eggs) as eggs,
                SUM(poultry_flock_egg_collection_cells.broken) as broken')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->cell_id][Carbon::parse($row->d)->toDateString()] = [
                'eggs' => (int) $row->eggs,
                'broken' => (int) $row->broken,
            ];
        }

        return $out;
    }

    /** @return array<int, int> cell_id => eggs ever collected before $date */
    private function eggsBeforeDate(Flock $flock, string $date): array
    {
        return $this->cellRowsQuery($flock)
            ->whereDate('r.date', '<', $date)
            ->groupBy('poultry_flock_egg_collection_cells.cell_id')
            ->selectRaw('poultry_flock_egg_collection_cells.cell_id as cell_id, SUM(poultry_flock_egg_collection_cells.eggs) as eggs')
            ->pluck('eggs', 'cell_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function cellRowsQuery(Flock $flock)
    {
        return PoultryFlockEggCollectionCell::query()
            ->join('poultry_flock_egg_collections as c', 'c.id', '=', 'poultry_flock_egg_collection_cells.collection_id')
            ->join('poultry_flock_egg_reports as r', 'r.id', '=', 'c.egg_report_id')
            ->where('r.flock_id', $flock->id)
            ->whereNull('r.deleted_at');
    }

    /**
     * @param  array<string, array{eggs: int, broken: int}>  $series  date => totals for this cell
     * @return array<string, mixed>
     */
    private function analyseCell(
        PoultryHouseCell $cell,
        string $since,
        array $recordedDates,
        array $windowDates,
        ?string $previousDate,
        array $series,
        int $everBefore,
        int $silentDays,
    ): array {
        $tracked = fn (string $d) => $d >= $since || isset($series[$d]);
        $eggsOn = fn (string $d) => $series[$d]['eggs'] ?? 0;

        $date = $recordedDates[0];
        $today = $eggsOn($date);
        $broken = $series[$date]['broken'] ?? 0;
        $prev = $previousDate && $tracked($previousDate) ? $eggsOn($previousDate) : null;
        $hadPriorTracked = collect(array_slice($recordedDates, 1))->contains(fn ($d) => $tracked($d));

        // Streaks over recorded days, newest first.
        $zeroStreak = 0;
        $layingStreak = 0;
        foreach ($recordedDates as $d) {
            if (! $tracked($d)) {
                break;
            }
            if ($eggsOn($d) === 0 && $layingStreak === 0) {
                $zeroStreak++;
            } elseif ($eggsOn($d) > 0 && $zeroStreak === 0) {
                $layingStreak++;
            } else {
                break;
            }
        }

        $windowTracked = array_values(array_filter($windowDates, $tracked));
        $windowEggs = array_sum(array_map($eggsOn, $windowTracked));
        $windowPossible = count($windowTracked) * max(1, $cell->birds);
        $windowRate = $windowPossible > 0 ? round($windowEggs / $windowPossible * 100, 1) : null;
        $history = array_values(array_filter(array_slice($windowTracked, 1), fn ($d) => $d !== $date));
        $avgBefore = count($history) ? round(array_sum(array_map($eggsOn, $history)) / count($history), 2) : null;

        $status = match (true) {
            ! $hadPriorTracked => self::STATUS_NO_HISTORY,
            $today > 0 && $everBefore === 0 => self::STATUS_FIRST_EGG,
            $prev !== null && $today > $prev => self::STATUS_INCREASE,
            $prev !== null && $prev > 0 && $today === 0 => self::STATUS_STOPPED,
            $prev !== null && $today < $prev => self::STATUS_DECREASE,
            $today === 0 && $zeroStreak >= $silentDays => self::STATUS_SILENT,
            default => self::STATUS_STEADY,
        };

        $flags = [];
        if ($today > $cell->birds) {
            $flags[] = 'over_capacity';
        }
        if ($cell->birds > 0 && $today === $cell->birds) {
            $flags[] = 'full';
        }
        if ($broken > 0 && ($broken >= 2 || $broken * 2 >= $today)) {
            $flags[] = 'high_broken';
        }
        if ($today === 0 && $zeroStreak >= $silentDays) {
            $flags[] = $everBefore === 0 ? 'never_laid' : 'silent';
        }

        return [
            'cell_id' => $cell->id,
            'label' => EggCollectionService::cellLabel($cell),
            'house_id' => $cell->row->house_id,
            'house_name' => $cell->row->house->name ?? null,
            'row_id' => $cell->row_id,
            'row_code' => $cell->row->code,
            'row_sort' => $cell->row->sort_order,
            'cell_number' => $cell->cell_number,
            'birds' => $cell->birds,
            'eggs' => $today,
            'broken' => $broken,
            'previous_eggs' => $prev,
            'change' => $prev === null ? null : $today - $prev,
            'eggs_before' => $everBefore,
            'average_before' => $avgBefore,
            'window_rate' => $windowRate,
            'window_days' => count($windowTracked),
            'zero_streak' => $zeroStreak,
            'laying_streak' => $layingStreak,
            'status' => $status,
            'flags' => $flags,
        ];
    }

    /** Flag the best cells and the ones well below their row over the window. */
    private function markRelativePerformance(array $cells): array
    {
        $rowRates = [];
        foreach ($cells as $c) {
            if ($c['window_rate'] !== null && $c['window_days'] >= 3) {
                $rowRates[$c['row_id']][] = $c['window_rate'];
            }
        }
        $rowAvg = array_map(fn ($r) => array_sum($r) / count($r), $rowRates);

        foreach ($cells as &$c) {
            if ($c['window_rate'] === null || $c['window_days'] < 3) {
                continue;
            }
            if ($c['window_rate'] >= 90) {
                $c['flags'][] = 'top';
            }
            $avg = $rowAvg[$c['row_id']] ?? null;
            if ($avg !== null && $avg > 0 && $c['window_rate'] < 50 && $c['window_rate'] <= $avg * 0.6) {
                $c['flags'][] = 'weak';
            }
        }

        return $cells;
    }

    private function summary(array $cells, bool $hasPrevious): array
    {
        $col = collect($cells);
        $eggs = $col->sum('eggs');
        $birds = $col->sum('birds');
        $prevTracked = $col->whereNotNull('previous_eggs');
        $prevEggs = $hasPrevious ? $prevTracked->sum('previous_eggs') : null;
        $statusCount = fn ($s) => $col->where('status', $s)->count();
        $flagCount = fn ($f) => $col->filter(fn ($c) => in_array($f, $c['flags'], true))->count();

        return [
            'cells' => $col->count(),
            'birds' => $birds,
            'eggs' => $eggs,
            'broken' => $col->sum('broken'),
            'previous_eggs' => $prevEggs,
            'change' => $prevEggs === null ? null : $eggs - $prevEggs,
            'lay_rate' => $birds > 0 ? round($eggs / $birds * 100, 1) : null,
            'cells_laying' => $col->where('eggs', '>', 0)->count(),
            'previous_cells_laying' => $hasPrevious ? $prevTracked->where('previous_eggs', '>', 0)->count() : null,
            'counts' => [
                'first_egg' => $statusCount(self::STATUS_FIRST_EGG),
                'increase' => $statusCount(self::STATUS_INCREASE),
                'decrease' => $statusCount(self::STATUS_DECREASE),
                'stopped' => $statusCount(self::STATUS_STOPPED),
                'steady' => $statusCount(self::STATUS_STEADY),
                'silent' => $flagCount('silent'),
                'never_laid' => $flagCount('never_laid'),
                'over_capacity' => $flagCount('over_capacity'),
                'high_broken' => $flagCount('high_broken'),
                'full' => $flagCount('full'),
                'top' => $flagCount('top'),
                'weak' => $flagCount('weak'),
            ],
        ];
    }

    private function events(array $cells): array
    {
        $col = collect($cells);
        $item = function (array $c, string $detail) {
            return [
                'cell_id' => $c['cell_id'],
                'label' => $c['label'],
                'house_name' => $c['house_name'],
                'row_code' => $c['row_code'],
                'birds' => $c['birds'],
                'eggs' => $c['eggs'],
                'previous_eggs' => $c['previous_eggs'],
                'change' => $c['change'],
                'window_rate' => $c['window_rate'],
                'detail' => $detail,
            ];
        };
        $byStatus = fn (string $s) => $col->where('status', $s);
        $byFlag = fn (string $f) => $col->filter(fn ($c) => in_array($f, $c['flags'], true));
        $order = fn (Collection $c) => $c->sortBy(fn ($x) => sprintf('%08d-%05d-%05d', $x['house_id'], $x['row_sort'], $x['cell_number']));

        return [
            'first_egg' => $order($byStatus(self::STATUS_FIRST_EGG))
                ->map(fn ($c) => $item($c, "first egg{$this->plural($c['eggs'], '', ' ('.$c['eggs'].' eggs)')}"))->values()->all(),
            'increase' => $byStatus(self::STATUS_INCREASE)->sortByDesc('change')
                ->map(fn ($c) => $item($c, "{$c['previous_eggs']} → {$c['eggs']}"))->values()->all(),
            'decrease' => $byStatus(self::STATUS_DECREASE)->sortBy('change')
                ->map(fn ($c) => $item($c, "{$c['previous_eggs']} → {$c['eggs']}"))->values()->all(),
            'stopped' => $byStatus(self::STATUS_STOPPED)->sortByDesc('previous_eggs')
                ->map(fn ($c) => $item($c, "{$c['previous_eggs']} → 0"))->values()->all(),
            'silent' => $byFlag('silent')->sortByDesc('zero_streak')
                ->map(fn ($c) => $item($c, "no eggs for {$c['zero_streak']} recorded days"))->values()->all(),
            'never_laid' => $order($byFlag('never_laid'))
                ->map(fn ($c) => $item($c, "no egg yet in {$c['zero_streak']} recorded days"))->values()->all(),
            'over_capacity' => $byFlag('over_capacity')->sortByDesc(fn ($c) => $c['eggs'] - $c['birds'])
                ->map(fn ($c) => $item($c, "{$c['eggs']} eggs from {$c['birds']} birds"))->values()->all(),
            'high_broken' => $col->filter(fn ($c) => in_array('high_broken', $c['flags'], true))->sortByDesc('broken')
                ->map(fn ($c) => $item($c, "{$c['broken']} of {$c['eggs']} broken"))->values()->all(),
            'top' => $byFlag('top')->sortByDesc('window_rate')
                ->map(fn ($c) => $item($c, "{$c['window_rate']}% lay rate over {$c['window_days']} days"))->values()->all(),
            'weak' => $byFlag('weak')->sortBy('window_rate')
                ->map(fn ($c) => $item($c, "{$c['window_rate']}% lay rate over {$c['window_days']} days"))->values()->all(),
            'full' => $order($byFlag('full'))
                ->map(fn ($c) => $item($c, "every bird laid ({$c['eggs']}/{$c['birds']})"))->values()->all(),
        ];
    }

    private function rows(array $cells): array
    {
        return collect($cells)
            ->groupBy('row_id')
            ->map(function (Collection $rc) {
                $first = $rc->first();
                $birds = $rc->sum('birds');
                $eggs = $rc->sum('eggs');
                $prevTracked = $rc->whereNotNull('previous_eggs');
                $prev = $prevTracked->isEmpty() ? null : $prevTracked->sum('previous_eggs');

                return [
                    'row_id' => $first['row_id'],
                    'house_id' => $first['house_id'],
                    'house_name' => $first['house_name'],
                    'row_code' => $first['row_code'],
                    'sort' => $first['row_sort'],
                    'cells' => $rc->count(),
                    'birds' => $birds,
                    'eggs' => $eggs,
                    'broken' => $rc->sum('broken'),
                    'previous_eggs' => $prev,
                    'change' => $prev === null ? null : $eggs - $prev,
                    'lay_rate' => $birds > 0 ? round($eggs / $birds * 100, 1) : null,
                    'cells_laying' => $rc->where('eggs', '>', 0)->count(),
                    'up' => $rc->whereIn('status', [self::STATUS_INCREASE, self::STATUS_FIRST_EGG])->count(),
                    'down' => $rc->whereIn('status', [self::STATUS_DECREASE, self::STATUS_STOPPED])->count(),
                ];
            })
            ->sortBy(fn ($r) => sprintf('%08d-%05d-%s', $r['house_id'], $r['sort'], $r['row_code']))
            ->values()
            ->all();
    }

    private function grid(array $cells): array
    {
        return collect($cells)
            ->groupBy('house_id')
            ->map(fn (Collection $hc) => [
                'house_id' => $hc->first()['house_id'],
                'house_name' => $hc->first()['house_name'],
                'rows' => $hc->groupBy('row_id')
                    ->map(fn (Collection $rc) => [
                        'row_id' => $rc->first()['row_id'],
                        'code' => $rc->first()['row_code'],
                        'sort' => $rc->first()['row_sort'],
                        'cells' => $rc->sortBy('cell_number')->map(fn ($c) => array_intersect_key($c, array_flip([
                            'cell_id', 'label', 'cell_number', 'birds', 'eggs', 'broken', 'previous_eggs', 'change',
                            'average_before', 'window_rate', 'zero_streak', 'laying_streak', 'status', 'flags',
                        ])))->values()->all(),
                    ])
                    ->sortBy(fn ($r) => sprintf('%05d-%s', $r['sort'], $r['code']))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /** Daily totals across the flock's current cells, oldest first (up to 30 recorded days). */
    private function trend(Collection $cells, array $dates, array $eggsByCellDate): array
    {
        $birds = (int) $cells->sum('birds');
        $ids = $cells->pluck('id')->all();

        return collect(array_slice($dates, 0, 30))
            ->reverse()
            ->map(function (string $d) use ($ids, $eggsByCellDate, $birds) {
                $eggs = 0;
                $laying = 0;
                $broken = 0;
                foreach ($ids as $id) {
                    $e = $eggsByCellDate[$id][$d]['eggs'] ?? 0;
                    $eggs += $e;
                    $broken += $eggsByCellDate[$id][$d]['broken'] ?? 0;
                    $laying += $e > 0 ? 1 : 0;
                }

                return [
                    'date' => $d,
                    'eggs' => $eggs,
                    'broken' => $broken,
                    'cells_laying' => $laying,
                    'lay_rate' => $birds > 0 ? round($eggs / $birds * 100, 1) : null,
                ];
            })
            ->values()
            ->all();
    }

    private function plural(int $n, string $one, string $many): string
    {
        return $n === 1 ? $one : $many;
    }
}
