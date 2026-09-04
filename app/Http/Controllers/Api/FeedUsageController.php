<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\FeedingBatchSchedule;
use App\Models\FeedingBatchScheduleItem;
use App\Models\Flock;
use App\Models\PoultryFeedUsage;
use App\Models\FlockExpenditure;
use App\Models\PoultryFeedInventory;
use App\Services\FeedingDayService;
use App\Services\FeedingScheduleRangeService;
use App\Services\FeedUsageInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FeedUsageController extends ApiController
{
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed usages');
        }
        $query = PoultryFeedUsage::with([
            'flock:id,name,batch_number,farm_id',
            'feedType:id,name',
            'creator:id,name',
        ])->where('farm_id', $farm->id);

        if ($request->has('feed_inventory_id')) {
            $inventory = PoultryFeedInventory::where('id', $request->feed_inventory_id)
                ->where('farm_id', $farm->id)
                ->first();

            if (!$inventory) {
                return $this->sendNotFoundError('Feed inventory not found in this farm');
            }

            $query->where('poultry_feed_inventory_id', $inventory->id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('usage_date', 'like', "%{$search}%");
        }

        $sortField = $request->input('sort_by', 'usage_date');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        if ($request->has('feed_inventory_id') && !$request->has('per_page')) {
            $usages = $query->get();
            $usageIds = $usages->pluck('id')->all();
            $recordedIds = empty($usageIds)
                ? []
                : FlockExpenditure::where('source_type', 'feed_usage')
                    ->whereIn('source_id', $usageIds)
                    ->pluck('source_id')
                    ->all();
            $recordedLookup = array_fill_keys($recordedIds, true);

            $usages->each(function (PoultryFeedUsage $usage) use ($recordedLookup) {
                $usage->setAttribute('has_expenditure', isset($recordedLookup[$usage->id]));
            });

            return $this->sendResponse($usages, 'Feed usages retrieved successfully');
        }

        $perPage = $request->input('per_page', 10);
        $usages = $query->paginate($perPage);
        return $this->sendResponse($usages, 'Feed usages retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create feed usages');
        }
        $validator = Validator::make($request->all(), [
            'poultry_feed_inventory_id' => 'nullable|exists:poultry_feed_inventories,id',
            'poultry_feed_type_id' => 'required|exists:poultry_feed_types,id',
            'flock_id' => 'required|exists:flocks,id',
            'quantity' => 'required|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'usage_date' => 'required|date',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        [$flock, $inactiveResponse] = $this->activeFlockForFarm((int) $request->flock_id, $farm->id);
        if ($inactiveResponse) {
            return $inactiveResponse;
        }

        try {
            $usage = DB::transaction(function () use ($request, $farm, $user) {
                $feedInventory = FeedUsageInventoryService::resolveOrCreateInventory(
                    (int) $farm->id,
                    (int) $request->poultry_feed_type_id,
                    $user->id,
                    $request->filled('poultry_feed_inventory_id')
                        ? (int) $request->poultry_feed_inventory_id
                        : null
                );

                FeedUsageInventoryService::deductFromInventory($feedInventory, (float) $request->quantity);

                $unitCost = $request->filled('unit_cost')
                    ? (float) $request->unit_cost
                    : (float) ($feedInventory->unit_cost ?? 0);

                $usage = PoultryFeedUsage::create([
                    'farm_id' => $farm->id,
                    'poultry_feed_inventory_id' => $feedInventory->id,
                    'poultry_feed_type_id' => $request->poultry_feed_type_id,
                    'flock_id' => $request->flock_id,
                    'quantity' => $request->quantity,
                    'unit_cost' => $unitCost,
                    'usage_date' => $request->usage_date,
                    'created_by' => auth()->id(),
                ]);

                $this->syncBatchScheduleItem(
                    $request->flock_id,
                    $request->poultry_feed_type_id,
                    $request->quantity,
                    $request->usage_date
                );

                return $usage;
            });

            FlockExpenditure::recordFromFeedUsage($usage);

            return $this->sendResponse($usage->load(['feedInventory', 'feedType']), 'Feed usage created successfully', 201);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    public function show(Request $request, $farm, PoultryFeedUsage $usage)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed usages');
        }
        if ($usage->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed usage not found in this farm');
        }
        return $this->sendResponse($usage, 'Feed usage retrieved successfully');
    }

    public function update(Request $request, $farm, PoultryFeedUsage $usage)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update feed usages');
        }
        if ($usage->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed usage not found in this farm');
        }

        $flock = Flock::find($usage->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'poultry_feed_inventory_id' => 'sometimes|required|exists:poultry_feed_inventories,id',
            'move_quantity' => 'sometimes|numeric|min:0.01',
            'poultry_feed_type_id' => 'sometimes|required|exists:poultry_feed_types,id',
            'flock_id' => 'sometimes|required|exists:flocks,id',
            'quantity' => 'sometimes|numeric|min:0',
            'unit_cost' => 'sometimes|numeric|min:0',
            'usage_date' => 'sometimes|date',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        try {
            $splitUsage = null;

            $usage = DB::transaction(function () use ($request, $usage, $farm, &$splitUsage) {
                $newQuantity = $request->has('quantity') ? (float) $request->quantity : (float) $usage->quantity;
                $newInventoryId = $request->has('poultry_feed_inventory_id')
                    ? (int) $request->poultry_feed_inventory_id
                    : (int) $usage->poultry_feed_inventory_id;

                $quantityChanged = $request->has('quantity')
                    && round($newQuantity, 3) !== round((float) $usage->quantity, 3);
                $inventoryChanged = $request->has('poultry_feed_inventory_id')
                    && $newInventoryId !== (int) $usage->poultry_feed_inventory_id;

                if ($inventoryChanged) {
                    $newInventory = PoultryFeedInventory::where('farm_id', $farm->id)
                        ->where('id', $newInventoryId)
                        ->first();

                    if (!$newInventory) {
                        throw new \RuntimeException('Destination feed inventory not found in this farm.');
                    }

                    $moveQuantity = $request->has('move_quantity')
                        ? (float) $request->move_quantity
                        : null;

                    $moveResult = FeedUsageInventoryService::moveUsageToInventory(
                        $usage,
                        $newInventory,
                        $moveQuantity
                    );

                    $usage = $moveResult['usage'];
                    $splitUsage = $moveResult['moved_usage'];

                    if ($splitUsage) {
                        FlockExpenditure::recordFromFeedUsage($splitUsage);
                        $this->syncBatchScheduleItem(
                            $splitUsage->flock_id,
                            $splitUsage->poultry_feed_type_id,
                            $splitUsage->quantity,
                            $splitUsage->usage_date?->toDateString() ?? $splitUsage->usage_date
                        );
                    }

                    FlockExpenditure::recordFromFeedUsage($usage);
                    $this->syncBatchScheduleItem(
                        $usage->flock_id,
                        $usage->poultry_feed_type_id,
                        $usage->quantity,
                        $usage->usage_date?->toDateString() ?? $usage->usage_date
                    );

                    return $usage;
                }

                $updatePayload = $request->only([
                    'poultry_feed_inventory_id',
                    'poultry_feed_type_id',
                    'flock_id',
                    'quantity',
                    'unit_cost',
                    'usage_date',
                ]);

                if ($quantityChanged) {
                    FeedUsageInventoryService::applyUsageChange($usage, $newQuantity, $newInventoryId);
                }

                $usage->update($updatePayload);

                $usage = $usage->fresh();

                if (
                    $request->has('quantity')
                    || $request->has('flock_id')
                    || $request->has('poultry_feed_type_id')
                    || $request->has('usage_date')
                ) {
                    $this->syncBatchScheduleItem(
                        $usage->flock_id,
                        $usage->poultry_feed_type_id,
                        $usage->quantity,
                        $usage->usage_date?->toDateString() ?? $usage->usage_date
                    );
                }

                FlockExpenditure::recordFromFeedUsage($usage);

                return $usage;
            });

            $usage->load(['feedInventory', 'feedType', 'flock', 'creator']);

            if ($splitUsage) {
                $splitUsage->load(['feedInventory', 'feedType', 'flock', 'creator']);

                return $this->sendResponse([
                    'usage' => $usage,
                    'split_usage' => $splitUsage,
                ], 'Feed usage updated successfully');
            }

            return $this->sendResponse($usage, 'Feed usage updated successfully');
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    public function destroy(Request $request, $farm, PoultryFeedUsage $usage)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete feed usages');
        }
        if ($usage->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed usage not found in this farm');
        }

        $flock = Flock::find($usage->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        DB::transaction(function () use ($usage) {
            FlockExpenditure::deleteForSource('feed_usage', $usage->id);
            FeedUsageInventoryService::restoreOnDelete($usage);
            $usage->delete();
        });

        return $this->sendResponse(null, 'Feed usage deleted successfully');
    }

    /**
     * Ensure a flock expenditure exists for this feed usage (create if missing).
     */
    public function forceExpenditure(Request $request, $farm, PoultryFeedUsage $usage)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (!$user->hasPermissionTo('update feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to force feed usage expenditure');
        }

        if ($usage->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed usage not found in this farm');
        }

        $existing = FlockExpenditure::where('source_type', 'feed_usage')
            ->where('source_id', $usage->id)
            ->first();

        if ($existing) {
            return $this->sendResponse([
                'expenditure' => $existing,
                'created' => false,
                'has_expenditure' => true,
            ], 'Expenditure already recorded for this feed usage');
        }

        $usage->loadMissing('feedInventory');
        $expenditure = FlockExpenditure::recordFromFeedUsage($usage);

        if (!$expenditure) {
            return $this->sendError(
                'Unable to record expenditure. Feed usage must have a unit cost greater than zero.',
                [],
                422
            );
        }

        return $this->sendResponse([
            'expenditure' => $expenditure,
            'created' => true,
            'has_expenditure' => true,
        ], 'Expenditure recorded successfully', 201);
    }

    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed usages');
        }
        $query = PoultryFeedUsage::where('farm_id', $farm->id);
        $statistics = [
            'total_feed_usages' => $query->count(),
            'total_quantity' => $query->sum('quantity'),
            'by_feed_type' => $query->selectRaw('poultry_feed_type_id, sum(quantity) as total_quantity')->groupBy('poultry_feed_type_id')->get(),
        ];
        return $this->sendResponse($statistics, 'Feed usage statistics retrieved successfully');
    }

    /**
     * Auto-create or update a FeedingBatchScheduleItem when feed usage is recorded.
     *
     * Determines the feeding day from the flock's arrival date and age offset,
     * finds the matching schedule item, then creates or updates the batch item.
     */
    protected function syncBatchScheduleItem($flockId, $feedTypeId, $quantity, $usageDate)
    {
        if (!$flockId || !$feedTypeId) {
            return;
        }

        $flock = Flock::find($flockId);
        if (!$flock) {
            return;
        }

        // Find the feeding batch schedule for this flock
        $batchSchedule = FeedingBatchSchedule::where('flock_id', $flockId)->first();
        if (!$batchSchedule) {
            return;
        }

        $schedule = $batchSchedule->schedule;
        if (!$schedule || !$schedule->items) {
            return;
        }

        $feedingDay = FeedingDayService::feedingDayForDate($flock, $usageDate);

        $batchSchedule->loadMissing('schedule.items');
        $schedule = $batchSchedule->schedule;
        if (!$schedule) {
            return;
        }

        $resolved = app(FeedingScheduleRangeService::class)->resolveForDay($schedule, $feedingDay);

        // Prefer the range that matches both the day and the feed type used.
        $scheduleItem = null;
        if ($resolved && (int) $resolved->feed_type_id === (int) $feedTypeId) {
            $scheduleItem = $resolved;
        } else {
            $scheduleItem = $schedule->items
                ->first(fn ($item) => (int) $item->feed_type_id === (int) $feedTypeId && $item->coversDay($feedingDay));
        }

        if (!$scheduleItem) {
            return;
        }

        $perBirdQuantity = FeedingDayService::perBirdGramsFromTotalKg((float) $quantity, $flock);

        // One batch item per (batch schedule, date) — avoid duplicates when feed type differs.
        $existingItem = FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batchSchedule->id)
            ->whereDate('feeding_date', $usageDate)
            ->first();

        $totalKg = round((float) $quantity, 3);

        if ($existingItem) {
            $existingItem->update([
                'feeding_schedule_item_id' => $scheduleItem->id,
                'actual_quantity' => round($perBirdQuantity, 2),
                'actual_total_kg' => $totalKg > 0 ? $totalKg : null,
                'status' => 'completed',
            ]);
        } else {
            FeedingBatchScheduleItem::create([
                'feeding_batch_schedule_id' => $batchSchedule->id,
                'feeding_schedule_item_id' => $scheduleItem->id,
                'actual_feeding_time' => $scheduleItem->feeding_times,
                'actual_quantity' => round($perBirdQuantity, 2),
                'actual_total_kg' => $totalKg > 0 ? $totalKg : null,
                'feeding_date' => $usageDate,
                'status' => 'completed',
            ]);
        }
    }
} 