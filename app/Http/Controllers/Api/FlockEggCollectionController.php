<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\Flock;
use App\Models\PoultryFlockEggCollection;
use App\Models\PoultryFlockEggCollectionCell;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryHouseCell;
use App\Services\EggCollection\CellInsightService;
use App\Services\EggCollection\EggCollectionService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Egg collection rounds for a flock. Several rounds a day add up to the daily egg report.
 * A round is either a plain total or per-cell counts from the flock's cells.
 */
class FlockEggCollectionController extends ApiController
{
    public function __construct(private EggCollectionService $service)
    {
    }

    /** The flock's cells grouped by house and row, plus what has been recorded on the date so far. */
    public function sheet(Request $request, $farm, $flock)
    {
        [$farm, $flock, $error] = $this->resolve($request, $farm, $flock, 'view flock egg reports');
        if ($error) {
            return $error;
        }

        $date = $request->input('date', now()->toDateString());
        $cells = $this->service->flockCells($flock);
        $report = $this->reportOn($flock, $date);
        $recorded = $report ? $this->service->cellTotalsForReport($report) : [];

        $houses = $cells->groupBy(fn ($c) => $c->row->house_id)->map(function ($houseCells) use ($recorded) {
            $house = $houseCells->first()->row->house;

            return [
                'house_id' => $house->id,
                'house_name' => $house->name,
                'rows' => $houseCells->groupBy('row_id')->map(function ($rowCells) use ($recorded) {
                    $row = $rowCells->first()->row;

                    return [
                        'row_id' => $row->id,
                        'code' => $row->code,
                        'cells' => $rowCells->map(fn (PoultryHouseCell $c) => [
                            'id' => $c->id,
                            'cell_number' => $c->cell_number,
                            'label' => $row->code.$c->cell_number,
                            'birds' => $c->birds,
                            'recorded_eggs' => $recorded[$c->id]['eggs'] ?? 0,
                            'recorded_broken' => $recorded[$c->id]['broken'] ?? 0,
                        ])->values(),
                    ];
                })->values(),
            ];
        })->values();

        return $this->sendResponse([
            'flock_id' => $flock->id,
            'date' => $date,
            'has_cells' => $cells->isNotEmpty(),
            'total_cells' => $cells->count(),
            'total_birds_in_cells' => (int) $cells->sum('birds'),
            'flock_bird_count' => $flock->birdCountOnDate($date),
            'houses' => $houses,
            'report' => $report ? $this->reportPayload($report) : null,
        ], 'Egg sheet retrieved successfully');
    }

    public function index(Request $request, $farm, $flock)
    {
        [$farm, $flock, $error] = $this->resolve($request, $farm, $flock, 'view flock egg reports');
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), ['date' => 'required|date']);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $report = $this->reportOn($flock, $request->input('date'));

        return $this->sendResponse($report ? $this->reportPayload($report) : null, 'Egg collections retrieved successfully');
    }

    public function store(Request $request, $farm, $flock)
    {
        [$farm, $flock, $error] = $this->resolve($request, $farm, $flock, 'create flock egg reports', true);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), $this->rules(true));
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $date = Carbon::parse($request->input('date'))->toDateString();
        if ($date > now()->toDateString()) {
            return $this->sendValidationError('Validation failed', ['date' => ['Date cannot be in the future.']]);
        }

        return DB::transaction(function () use ($request, $flock, $date) {
            $report = $this->service->reportForDay($flock, $date, $request->user()->id);
            $this->service->preserveExistingTotal($report);

            [$values, $cellRows, $warnings, $error] = $this->buildRound($request, $flock, $report, null);
            if ($error) {
                // Roll back the report/legacy round created above.
                throw new HttpResponseException($error);
            }

            $collection = PoultryFlockEggCollection::create($values + [
                'egg_report_id' => $report->id,
                'round_no' => $this->service->nextRoundNo($report),
                'source' => 'manual',
                'recorded_by' => $request->user()->id,
            ]);
            $this->saveCells($collection, $cellRows);

            $report = $this->service->recalculate($report->fresh());

            return $this->sendResponse([
                'collection' => $this->collectionPayload($collection->fresh(['cells.cell.row', 'recordedBy:id,name'])),
                'report' => $this->reportPayload($report),
                'warnings' => $warnings,
            ], 'Egg collection recorded', 201);
        });
    }

    public function update(Request $request, $farm, $flock, $collection)
    {
        [$farm, $flock, $error] = $this->resolve($request, $farm, $flock, 'update flock egg reports', true);
        if ($error) {
            return $error;
        }

        $collection = $this->collectionFor($flock, $collection);
        if (! $collection) {
            return $this->sendNotFoundError('Egg collection not found');
        }

        $validator = Validator::make($request->all(), $this->rules(false));
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        return DB::transaction(function () use ($request, $flock, $collection) {
            $report = $collection->report;
            [$values, $cellRows, $warnings, $error] = $this->buildRound($request, $flock, $report, $collection);
            if ($error) {
                throw new HttpResponseException($error);
            }

            $collection->update($values + ['recorded_by' => $request->user()->id]);
            $collection->cells()->delete();
            $this->saveCells($collection, $cellRows);

            $report = $this->service->recalculate($report->fresh());

            return $this->sendResponse([
                'collection' => $this->collectionPayload($collection->fresh(['cells.cell.row', 'recordedBy:id,name'])),
                'report' => $this->reportPayload($report),
                'warnings' => $warnings,
            ], 'Egg collection updated');
        });
    }

    public function destroy(Request $request, $farm, $flock, $collection)
    {
        [$farm, $flock, $error] = $this->resolve($request, $farm, $flock, 'delete flock egg reports', true);
        if ($error) {
            return $error;
        }

        $collection = $this->collectionFor($flock, $collection);
        if (! $collection) {
            return $this->sendNotFoundError('Egg collection not found');
        }

        $report = DB::transaction(function () use ($collection) {
            $report = $collection->report;
            $collection->delete();

            return $this->service->recalculate($report->fresh());
        });

        return $this->sendResponse(['report' => $report ? $this->reportPayload($report) : null], 'Egg collection deleted');
    }

    /** Per-cell lay rate over a date range, weakest cells first. */
    public function performance(Request $request, $farm, $flock)
    {
        [$farm, $flock, $error] = $this->resolve($request, $farm, $flock, 'view flock egg reports');
        if ($error) {
            return $error;
        }

        $to = $request->input('to', now()->toDateString());
        $from = $request->input('from', Carbon::parse($to)->subDays(13)->toDateString());

        $rows = PoultryFlockEggCollectionCell::query()
            ->join('poultry_flock_egg_collections as c', 'c.id', '=', 'poultry_flock_egg_collection_cells.collection_id')
            ->join('poultry_flock_egg_reports as r', 'r.id', '=', 'c.egg_report_id')
            ->where('r.flock_id', $flock->id)
            ->whereNull('r.deleted_at')
            ->whereDate('r.date', '>=', $from)
            ->whereDate('r.date', '<=', $to)
            ->groupBy('poultry_flock_egg_collection_cells.cell_id')
            ->selectRaw('poultry_flock_egg_collection_cells.cell_id as cell_id,
                SUM(poultry_flock_egg_collection_cells.eggs) as eggs,
                SUM(poultry_flock_egg_collection_cells.broken) as broken,
                COUNT(DISTINCT r.date) as days')
            ->get()
            ->keyBy('cell_id');

        $cells = $this->service->flockCells($flock)->map(function (PoultryHouseCell $c) use ($rows) {
            $stat = $rows->get($c->id);
            $days = (int) ($stat->days ?? 0);
            $eggs = (int) ($stat->eggs ?? 0);
            $possible = $days * max(1, $c->birds);

            return [
                'cell_id' => $c->id,
                'label' => EggCollectionService::cellLabel($c),
                'house_id' => $c->row->house_id,
                'row_code' => $c->row->code,
                'cell_number' => $c->cell_number,
                'birds' => $c->birds,
                'days_recorded' => $days,
                'eggs' => $eggs,
                'broken' => (int) ($stat->broken ?? 0),
                'lay_rate' => $possible > 0 ? round($eggs / $possible * 100, 1) : null,
            ];
        })->sortBy(fn ($c) => $c['lay_rate'] ?? 1000)->values();

        return $this->sendResponse(['from' => $from, 'to' => $to, 'cells' => $cells], 'Cell performance retrieved successfully');
    }

    /**
     * Day-on-day cell analysis: first eggs, cells that went up, down or stopped, silent cells,
     * more eggs than birds, broken eggs, best and weakest cells, row totals and a trend.
     */
    public function insights(Request $request, CellInsightService $insights, $farm, $flock)
    {
        [$farm, $flock, $error] = $this->resolve($request, $farm, $flock, 'view flock egg reports');
        if ($error) {
            return $error;
        }

        $data = $this->insightParams($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        return $this->sendResponse(
            $insights->analyse($flock, $data['date'], $data['window'], $data['silent_days']),
            'Cell insights retrieved successfully'
        );
    }

    /** The same analysis read by the AI, with a short summary, concerns and actions. */
    public function insightsAi(Request $request, CellInsightService $insights, $farm, $flock)
    {
        [$farm, $flock, $error] = $this->resolve($request, $farm, $flock, 'view flock egg reports');
        if ($error) {
            return $error;
        }

        $data = $this->insightParams($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $analysis = $insights->analyse($flock, $data['date'], $data['window'], $data['silent_days']);
        if (empty($analysis['has_data'])) {
            return $this->sendError('No by-cell collection recorded for this flock on '.$data['date'].'.', [], 422);
        }

        $ai = $insights->aiSummary($flock, $analysis);

        return $this->sendResponse([
            'date' => $analysis['date'],
            'previous_date' => $analysis['previous_date'],
            'ai_available' => $ai !== null,
            'ai_insights' => $ai['insights'] ?? null,
            'ai_text' => $ai['text'] ?? null,
        ], 'Cell AI insights retrieved successfully');
    }

    /** Chat about the flock's cell egg collection on a day. The client keeps the conversation. */
    public function insightsChat(Request $request, CellInsightService $insights, $farm, $flock)
    {
        [$farm, $flock, $error] = $this->resolve($request, $farm, $flock, 'view flock egg reports');
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'messages' => 'required|array|min:1|max:40',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:4000',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation error', $validator->errors()->toArray());
        }
        $messages = $request->input('messages');
        if (end($messages)['role'] !== 'user') {
            return $this->sendValidationError('Validation error', ['messages' => ['The last message must be from the user.']]);
        }

        $data = $this->insightParams($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $analysis = $insights->analyse($flock, $data['date'], $data['window'], $data['silent_days']);
        if (empty($analysis['has_data'])) {
            return $this->sendError('No by-cell collection recorded for this flock on '.$data['date'].'.', [], 422);
        }

        $reply = $insights->chat($flock, $analysis, $messages);

        return $this->sendResponse([
            'ai_available' => $reply !== null,
            'reply' => $reply,
        ], 'Cell insights chat reply');
    }

    /** @return array{date: string, window: int, silent_days: int}|JsonResponse */
    private function insightParams(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date',
            'window' => 'nullable|integer|min:2|max:30',
            'silent_days' => 'nullable|integer|min:2|max:30',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError("Validation error", $validator->errors()->toArray());
        }

        return [
            'date' => $request->input('date', now()->toDateString()),
            'window' => (int) $request->input('window', 7),
            'silent_days' => (int) $request->input('silent_days', 3),
        ];
    }

    private function rules(bool $creating): array
    {
        return [
            'date' => ($creating ? 'required' : 'sometimes').'|date',
            'collected_at' => 'nullable|date_format:H:i',
            'entry_mode' => 'required|in:total,cell',
            'eggs_collected' => 'required_if:entry_mode,total|integer|min:0',
            'cells' => 'required_if:entry_mode,cell|array',
            'cells.*.cell_id' => 'required|integer',
            'cells.*.eggs' => 'required|integer|min:0|max:500',
            'cells.*.broken' => 'nullable|integer|min:0|max:500',
            'eggs_broken_extra' => 'nullable|integer|min:0',
            'average_egg_weight' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Validate a round against the flock's cells and build the values to save.
     *
     * @return array{0: array, 1: array, 2: array<int, string>, 3: ?JsonResponse}
     */
    private function buildRound(Request $request, Flock $flock, PoultryFlockEggReport $report, ?PoultryFlockEggCollection $editing): array
    {
        $mode = $request->input('entry_mode');
        $extraBroken = (int) $request->input('eggs_broken_extra', 0);
        $warnings = [];
        $cellRows = [];
        $brokenCells = 0;

        if ($mode === PoultryFlockEggCollection::MODE_CELL) {
            $input = collect($request->input('cells', []));
            if ($input->pluck('cell_id')->duplicates()->isNotEmpty()) {
                return [[], [], [], $this->sendValidationError('Validation failed', ['cells' => ['A cell appears more than once.']])];
            }

            $cells = PoultryHouseCell::with('row')
                ->whereIn('id', $input->pluck('cell_id'))
                ->where('flock_id', $flock->id)
                ->where('is_active', true)
                ->get()
                ->keyBy('id');
            if ($cells->count() !== $input->count()) {
                return [[], [], [], $this->sendValidationError('Validation failed', ['cells' => ['One or more cells are not assigned to this flock.']])];
            }

            $already = $this->service->cellTotalsForReport($report, $editing?->id);
            $eggs = 0;
            foreach ($input as $item) {
                $cell = $cells[$item['cell_id']];
                $label = EggCollectionService::cellLabel($cell);
                $cellEggs = (int) $item['eggs'];
                $cellBroken = (int) ($item['broken'] ?? 0);

                if ($cellBroken > $cellEggs) {
                    return [[], [], [], $this->sendValidationError('Validation failed', [
                        'cells' => ["{$label}: broken eggs cannot be more than eggs collected."],
                    ])];
                }

                $dayTotal = $cellEggs + ($already[$cell->id]['eggs'] ?? 0);
                if ($cell->birds > 0 && $dayTotal > $cell->birds) {
                    $warnings[] = "{$label} has {$dayTotal} eggs today from {$cell->birds} birds.";
                }

                $eggs += $cellEggs;
                $brokenCells += $cellBroken;
                $cellRows[] = ['cell_id' => $cell->id, 'eggs' => $cellEggs, 'broken' => $cellBroken, 'birds' => $cell->birds];
            }
        } else {
            $eggs = (int) $request->input('eggs_collected', 0);
        }

        if ($brokenCells + $extraBroken > $eggs) {
            return [[], [], [], $this->sendValidationError('Validation failed', [
                'eggs_broken_extra' => ['Broken eggs cannot exceed eggs collected.'],
            ])];
        }

        return [[
            'collected_at' => $request->input('collected_at'),
            'entry_mode' => $mode,
            'eggs_collected' => $eggs,
            'eggs_broken_cells' => $brokenCells,
            'eggs_broken_extra' => $extraBroken,
            'average_egg_weight' => $request->filled('average_egg_weight') ? $request->input('average_egg_weight') : null,
            'notes' => $request->input('notes'),
        ], $cellRows, $warnings, null];
    }

    private function saveCells(PoultryFlockEggCollection $collection, array $cellRows): void
    {
        if (empty($cellRows)) {
            return;
        }
        $now = now();
        PoultryFlockEggCollectionCell::insert(array_map(fn ($row) => $row + [
            'collection_id' => $collection->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $cellRows));
    }

    private function reportOn(Flock $flock, string $date): ?PoultryFlockEggReport
    {
        return PoultryFlockEggReport::where('flock_id', $flock->id)->whereDate('date', $date)->orderBy('id')->first();
    }

    private function collectionFor(Flock $flock, $id): ?PoultryFlockEggCollection
    {
        return PoultryFlockEggCollection::where('id', $id)
            ->whereHas('report', fn ($q) => $q->where('flock_id', $flock->id))
            ->first();
    }

    private function reportPayload(PoultryFlockEggReport $report): array
    {
        $report->load(['collections.cells.cell.row', 'collections.recordedBy:id,name', 'recordedBy:id,name']);

        $cellTotals = [];
        $rowTotals = [];
        foreach ($report->collections as $round) {
            foreach ($round->cells as $c) {
                $id = $c->cell_id;
                $cellTotals[$id] ??= [
                    'cell_id' => $id,
                    'label' => $c->cell ? EggCollectionService::cellLabel($c->cell) : (string) $id,
                    'row_code' => $c->cell?->row?->code,
                    'house_id' => $c->cell?->row?->house_id,
                    'birds' => $c->birds,
                    'eggs' => 0,
                    'broken' => 0,
                ];
                $cellTotals[$id]['eggs'] += $c->eggs;
                $cellTotals[$id]['broken'] += $c->broken;
                $code = $c->cell?->row?->code ?? '?';
                $rowTotals[$code] = ($rowTotals[$code] ?? 0) + $c->eggs;
            }
        }

        return [
            'id' => $report->id,
            'flock_id' => $report->flock_id,
            'date' => $report->date?->toDateString(),
            'entry_mode' => $report->entry_mode,
            'eggs_collected' => (int) $report->eggs_collected,
            'eggs_broken' => (int) $report->eggs_broken,
            'eggs_good' => max(0, (int) $report->eggs_collected - (int) $report->eggs_broken),
            'average_egg_weight' => (float) $report->average_egg_weight,
            'production_percentage' => (float) $report->production_percentage,
            'bird_count' => (int) $report->bird_count,
            'notes' => $report->notes,
            'rounds' => $report->collections->map(fn ($c) => $this->collectionPayload($c, false))->values(),
            'row_totals' => $rowTotals,
            'cell_totals' => array_values($cellTotals),
        ];
    }

    private function collectionPayload(PoultryFlockEggCollection $c, bool $withCells = true): array
    {
        $payload = [
            'id' => $c->id,
            'egg_report_id' => $c->egg_report_id,
            'round_no' => $c->round_no,
            'collected_at' => $c->collected_at ? substr((string) $c->collected_at, 0, 5) : null,
            'entry_mode' => $c->entry_mode,
            'source' => $c->source,
            'eggs_collected' => $c->eggs_collected,
            'eggs_broken_cells' => $c->eggs_broken_cells,
            'eggs_broken_extra' => $c->eggs_broken_extra,
            'eggs_broken' => $c->eggs_broken,
            'average_egg_weight' => $c->average_egg_weight !== null ? (float) $c->average_egg_weight : null,
            'notes' => $c->notes,
            'recorded_by' => $c->recorded_by,
            'recorded_by_name' => $c->recordedBy?->name,
        ];

        $payload['cells'] = $c->cells->map(fn ($cell) => [
            'cell_id' => $cell->cell_id,
            'label' => $cell->cell ? EggCollectionService::cellLabel($cell->cell) : null,
            'eggs' => $cell->eggs,
            'broken' => $cell->broken,
            'birds' => $cell->birds,
        ])->values();

        return $payload;
    }

    /**
     * @return array{0: ?Farm, 1: ?Flock, 2: ?JsonResponse}
     */
    private function resolve(Request $request, $farmId, $flockId, string $permission, bool $write = false): array
    {
        $farm = Farm::findOrFail($farmId);
        if (! $request->user()->hasPermissionTo($permission, 'api', $farm)) {
            return [null, null, $this->sendUnauthorizedError("Unauthorized to {$permission}")];
        }

        $flock = Flock::where('id', $flockId)->where('farm_id', $farm->id)->first();
        if (! $flock) {
            return [null, null, $this->sendNotFoundError('Flock not found in this farm')];
        }

        if ($write && ($response = $this->ensureFlockIsActive($flock))) {
            return [null, null, $response];
        }

        return [$farm, $flock, null];
    }
}
