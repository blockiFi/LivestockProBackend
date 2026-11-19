<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\Flock;
use App\Models\PoultryHouse;
use App\Models\PoultryHouseCell;
use App\Models\PoultryHouseRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cage layout of a poultry house for cell-based egg collection:
 * rows (A, B...), cells (A1, A2...), birds per cell and which flock occupies each cell.
 */
class PoultryHouseEggLayoutController extends ApiController
{
    public function show(Request $request, $farm, $house)
    {
        [$farm, $house, $error] = $this->resolve($farm, $house, false);
        if ($error) {
            return $error;
        }

        return $this->sendResponse($this->layoutPayload($house), 'Egg layout retrieved successfully');
    }

    public function updateSettings(Request $request, $farm, $house)
    {
        [$farm, $house, $error] = $this->resolve($farm, $house, true);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'egg_collection_mode' => 'sometimes|in:total,cell',
            'birds_per_cell' => 'sometimes|nullable|integer|min:1|max:50',
            'apply_birds_to_cells' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        DB::transaction(function () use ($request, $house) {
            $house->update($request->only(['egg_collection_mode', 'birds_per_cell']));

            if ($request->boolean('apply_birds_to_cells') && $house->birds_per_cell) {
                $rowIds = $house->eggRows()->whereNull('birds_per_cell')->pluck('id');
                PoultryHouseCell::whereIn('row_id', $rowIds)->update(['birds' => $house->birds_per_cell]);
            }
        });

        return $this->sendResponse($this->layoutPayload($house->fresh()), 'Egg layout settings updated');
    }

    /** Create several rows at once, e.g. A–F with 54 cells each. Existing row codes are skipped. */
    public function generate(Request $request, $farm, $house)
    {
        [$farm, $house, $error] = $this->resolve($farm, $house, true);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'codes' => 'required|array|min:1|max:52',
            'codes.*' => 'required|string|max:10|distinct',
            'cells_per_row' => 'required|integer|min:1|max:500',
            'birds_per_cell' => 'nullable|integer|min:1|max:50',
            'flock_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $flockId = $request->input('flock_id');
        if ($flockId && ! Flock::where('id', $flockId)->where('farm_id', $farm->id)->exists()) {
            return $this->sendValidationError('Validation failed', ['flock_id' => ['Flock not found in this farm.']]);
        }

        DB::transaction(function () use ($request, $house, $farm, $flockId) {
            $updates = ['egg_collection_mode' => 'cell'];
            if ($request->filled('birds_per_cell')) {
                $updates['birds_per_cell'] = (int) $request->input('birds_per_cell');
            }
            $house->update($updates);
            $house->refresh();

            $existing = $house->eggRows()->pluck('code')->map(fn ($c) => strtoupper($c))->all();
            $sort = (int) $house->eggRows()->max('sort_order');

            foreach ($request->input('codes') as $code) {
                $code = strtoupper(trim($code));
                if (in_array($code, $existing, true)) {
                    continue;
                }
                $row = PoultryHouseRow::create([
                    'farm_id' => $farm->id,
                    'house_id' => $house->id,
                    'code' => $code,
                    'cell_count' => (int) $request->input('cells_per_row'),
                    'sort_order' => ++$sort,
                ]);
                $this->syncRowCells($row, $flockId ? (int) $flockId : null);
            }
        });

        return $this->sendResponse($this->layoutPayload($house->fresh()), 'Egg layout generated', 201);
    }

    public function storeRow(Request $request, $farm, $house)
    {
        [$farm, $house, $error] = $this->resolve($farm, $house, true);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:10',
            'cell_count' => 'required|integer|min:1|max:500',
            'birds_per_cell' => 'nullable|integer|min:1|max:50',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $code = strtoupper(trim($request->input('code')));
        if ($house->eggRows()->whereRaw('UPPER(code) = ?', [$code])->exists()) {
            return $this->sendValidationError('Validation failed', ['code' => ["Row {$code} already exists in this house."]]);
        }

        DB::transaction(function () use ($request, $house, $farm, $code) {
            $row = PoultryHouseRow::create([
                'farm_id' => $farm->id,
                'house_id' => $house->id,
                'code' => $code,
                'cell_count' => (int) $request->input('cell_count'),
                'birds_per_cell' => $request->input('birds_per_cell'),
                'sort_order' => (int) $house->eggRows()->max('sort_order') + 1,
            ]);
            $this->syncRowCells($row);
        });

        return $this->sendResponse($this->layoutPayload($house->fresh()), 'Row added', 201);
    }

    public function updateRow(Request $request, $farm, $house, $row)
    {
        [$farm, $house, $error] = $this->resolve($farm, $house, true);
        if ($error) {
            return $error;
        }
        $row = $house->eggRows()->where('id', $row)->first();
        if (! $row) {
            return $this->sendNotFoundError('Row not found in this house');
        }

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|max:10',
            'cell_count' => 'sometimes|integer|min:1|max:500',
            'birds_per_cell' => 'sometimes|nullable|integer|min:1|max:50',
            'sort_order' => 'sometimes|integer|min:0',
            'apply_birds_to_cells' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        if ($request->has('code')) {
            $code = strtoupper(trim($request->input('code')));
            $clash = $house->eggRows()->where('id', '!=', $row->id)->whereRaw('UPPER(code) = ?', [$code])->exists();
            if ($clash) {
                return $this->sendValidationError('Validation failed', ['code' => ["Row {$code} already exists in this house."]]);
            }
            $request->merge(['code' => $code]);
        }

        DB::transaction(function () use ($request, $row) {
            $row->update($request->only(['code', 'cell_count', 'birds_per_cell', 'sort_order']));
            $row->refresh();
            $this->syncRowCells($row);

            if ($request->boolean('apply_birds_to_cells')) {
                $row->cells()->update(['birds' => $row->effectiveBirdsPerCell()]);
            }
        });

        return $this->sendResponse($this->layoutPayload($house->fresh()), 'Row updated');
    }

    /** Rows with recorded eggs are deactivated so history stays readable; empty rows are deleted. */
    public function destroyRow(Request $request, $farm, $house, $row)
    {
        [$farm, $house, $error] = $this->resolve($farm, $house, true);
        if ($error) {
            return $error;
        }
        $row = $house->eggRows()->where('id', $row)->first();
        if (! $row) {
            return $this->sendNotFoundError('Row not found in this house');
        }

        $hasHistory = PoultryHouseCell::where('row_id', $row->id)->whereHas('collectionCells')->exists();
        if ($hasHistory) {
            $row->update(['is_active' => false]);
            $row->cells()->update(['is_active' => false, 'flock_id' => null]);
        } else {
            $row->delete();
        }

        return $this->sendResponse($this->layoutPayload($house->fresh()), $hasHistory ? 'Row deactivated' : 'Row deleted');
    }

    /** Batch edit cells: birds, flock and active flag. */
    public function updateCells(Request $request, $farm, $house)
    {
        [$farm, $house, $error] = $this->resolve($farm, $house, true);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'cells' => 'required|array|min:1',
            'cells.*.id' => 'required|integer',
            'cells.*.birds' => 'sometimes|integer|min:0|max:50',
            'cells.*.flock_id' => 'sometimes|nullable|integer',
            'cells.*.is_active' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $input = collect($request->input('cells'));
        $rowIds = $house->eggRows()->pluck('id');
        $cells = PoultryHouseCell::whereIn('row_id', $rowIds)->whereIn('id', $input->pluck('id'))->get()->keyBy('id');
        if ($cells->count() !== $input->pluck('id')->unique()->count()) {
            return $this->sendValidationError('Validation failed', ['cells' => ['One or more cells do not belong to this house.']]);
        }

        $flockIds = $input->pluck('flock_id')->filter()->unique();
        if ($flockIds->isNotEmpty() && Flock::where('farm_id', $farm->id)->whereIn('id', $flockIds)->count() !== $flockIds->count()) {
            return $this->sendValidationError('Validation failed', ['flock_id' => ['Flock not found in this farm.']]);
        }

        DB::transaction(function () use ($input, $cells) {
            foreach ($input as $item) {
                $cells[$item['id']]->update(array_intersect_key($item, array_flip(['birds', 'flock_id', 'is_active'])));
            }
        });

        return $this->sendResponse($this->layoutPayload($house->fresh()), 'Cells updated');
    }

    /** Assign whole rows, or a range of cells in one row, to a flock (or clear with flock_id null). */
    public function assign(Request $request, $farm, $house)
    {
        [$farm, $house, $error] = $this->resolve($farm, $house, true);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'flock_id' => 'present|nullable|integer',
            'row_ids' => 'required_without:row_id|array',
            'row_ids.*' => 'integer',
            'row_id' => 'required_without:row_ids|integer',
            'from' => 'required_with:row_id|integer|min:1',
            'to' => 'required_with:row_id|integer|gte:from',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $flockId = $request->input('flock_id');
        if ($flockId && ! Flock::where('id', $flockId)->where('farm_id', $farm->id)->exists()) {
            return $this->sendValidationError('Validation failed', ['flock_id' => ['Flock not found in this farm.']]);
        }

        $houseRowIds = $house->eggRows()->pluck('id');
        $query = PoultryHouseCell::where('is_active', true);
        if ($request->filled('row_ids')) {
            $ids = collect($request->input('row_ids'))->intersect($houseRowIds);
            if ($ids->count() !== count($request->input('row_ids'))) {
                return $this->sendValidationError('Validation failed', ['row_ids' => ['One or more rows do not belong to this house.']]);
            }
            $query->whereIn('row_id', $ids);
        } else {
            if (! $houseRowIds->contains((int) $request->input('row_id'))) {
                return $this->sendValidationError('Validation failed', ['row_id' => ['Row does not belong to this house.']]);
            }
            $query->where('row_id', $request->input('row_id'))
                ->whereBetween('cell_number', [(int) $request->input('from'), (int) $request->input('to')]);
        }

        $count = $query->update(['flock_id' => $flockId]);

        return $this->sendResponse(
            $this->layoutPayload($house->fresh()) + ['assigned_cells' => $count],
            $flockId ? "Assigned {$count} cells" : "Cleared {$count} cells"
        );
    }

    /**
     * Make the row's cells match cell_count: create missing cells, reactivate cells back in range,
     * and drop cells beyond the count (deactivated if they hold history).
     */
    private function syncRowCells(PoultryHouseRow $row, ?int $flockId = null): void
    {
        $row->loadMissing('house');
        $birds = $row->effectiveBirdsPerCell();
        $existing = PoultryHouseCell::where('row_id', $row->id)->get()->keyBy('cell_number');

        for ($n = 1; $n <= $row->cell_count; $n++) {
            $cell = $existing->get($n);
            if (! $cell) {
                PoultryHouseCell::create([
                    'row_id' => $row->id,
                    'cell_number' => $n,
                    'birds' => $birds,
                    'flock_id' => $flockId,
                ]);
            } elseif (! $cell->is_active) {
                $cell->update(['is_active' => true]);
            }
        }

        foreach ($existing->filter(fn ($c) => $c->cell_number > $row->cell_count) as $cell) {
            if ($cell->collectionCells()->exists()) {
                $cell->update(['is_active' => false, 'flock_id' => null]);
            } else {
                $cell->delete();
            }
        }
    }

    private function layoutPayload(PoultryHouse $house): array
    {
        $rows = $house->eggRows()->with('cells')->get();
        $flockIds = $rows->flatMap(fn ($r) => $r->cells->pluck('flock_id'))->filter()->unique()->values();
        $flocks = Flock::whereIn('id', $flockIds)->get(['id', 'name', 'batch_number'])->keyBy('id');

        $activeCells = $rows->where('is_active', true)->flatMap(fn ($r) => $r->cells->where('is_active', true));
        $birdsByFlock = $activeCells->filter(fn ($c) => $c->flock_id)->groupBy('flock_id')->map(fn ($g) => (int) $g->sum('birds'));

        return [
            'house' => [
                'id' => $house->id,
                'name' => $house->name,
                'capacity' => $house->capacity,
                'egg_collection_mode' => $house->egg_collection_mode ?? 'total',
                'birds_per_cell' => $house->birds_per_cell,
            ],
            'rows' => $rows->map(fn (PoultryHouseRow $row) => [
                'id' => $row->id,
                'code' => $row->code,
                'cell_count' => $row->cell_count,
                'birds_per_cell' => $row->birds_per_cell,
                'effective_birds_per_cell' => $row->effectiveBirdsPerCell(),
                'sort_order' => $row->sort_order,
                'is_active' => $row->is_active,
                'cells' => $row->cells->map(fn (PoultryHouseCell $cell) => [
                    'id' => $cell->id,
                    'cell_number' => $cell->cell_number,
                    'label' => $row->code.$cell->cell_number,
                    'birds' => $cell->birds,
                    'flock_id' => $cell->flock_id,
                    'is_active' => $cell->is_active,
                ])->values(),
            ])->values(),
            'summary' => [
                'rows' => $rows->where('is_active', true)->count(),
                'cells' => $activeCells->count(),
                'birds' => (int) $activeCells->sum('birds'),
                'unassigned_cells' => $activeCells->whereNull('flock_id')->count(),
            ],
            'flocks' => $flockIds->map(fn ($id) => [
                'id' => $id,
                'name' => $flocks[$id]->name ?? $flocks[$id]->batch_number ?? "Flock #{$id}",
                'cells' => $activeCells->where('flock_id', $id)->count(),
                'birds' => $birdsByFlock[$id] ?? 0,
            ])->values(),
        ];
    }

    /**
     * @return array{0: ?Farm, 1: ?PoultryHouse, 2: ?JsonResponse}
     */
    private function resolve($farmId, $houseId, bool $manage): array
    {
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        $user = auth()->user();

        $allowed = $manage
            ? $user->can('manage poultry houses', 'api', $farm->id)
            : ($user->can('view poultry houses', 'api', $farm->id)
                || $user->can('view flock egg reports', 'api', $farm->id));
        if (! $allowed) {
            return [null, null, $this->sendError(
                $manage ? 'You do not have permission to manage poultry houses' : 'You do not have permission to view this house layout',
                [],
                403
            )];
        }

        $house = PoultryHouse::where('farm_id', $farm->id)->find($houseId);
        if (! $house) {
            return [null, null, $this->sendNotFoundError('Poultry house not found')];
        }

        return [$farm, $house, null];
    }
}
