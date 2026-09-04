<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Concerns\ChecksScheduleAccess;
use App\Models\FeedingBatchScheduleItem;
use App\Models\Flock;
use App\Models\FeedingBatchSchedule;
use App\Services\FeedingBatchScheduleItemService;
use App\Services\FeedingDayService;
use App\Services\FeedingMissedScheduleService;
use App\Services\FeedingScheduleRangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FeedingBatchScheduleItemController extends ApiController
{
    use ChecksScheduleAccess;

    public function index()
    {
        $feedingBatchScheduleId = request('feeding_batch_schedule_id');
        $farmId = null;
        if ($feedingBatchScheduleId) {
            $feedingBatchSchedule = \App\Models\FeedingBatchSchedule::find($feedingBatchScheduleId);
            $flock = $feedingBatchSchedule ? $feedingBatchSchedule->flock : null;
            $farmId = $flock ? $flock->farm_id : null;
        }
        if ($farmId && !$this->canViewFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding batch schedule items');
        }
        $items = FeedingBatchScheduleItem::with(['batchSchedule', 'scheduleItem'])->paginate(15);
        return $this->sendResponse($items, 'Feeding batch schedule items retrieved successfully');
    }

    public function store(Request $request)
    {
        $feedingBatchSchedule = \App\Models\FeedingBatchSchedule::find($request->feeding_batch_schedule_id);
        $flock = $feedingBatchSchedule ? $feedingBatchSchedule->flock : null;
        $farmId = $flock ? $flock->farm_id : null;
        if ($farmId && !$this->canCreateFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create feeding batch schedule items');
        }
        $validator = Validator::make($request->all(), [
            'feeding_batch_schedule_id' => 'required|exists:feeding_batch_schedules,id',
            'feeding_schedule_item_id' => 'required|exists:feeding_schedule_items,id',
            'actual_feeding_time' => 'nullable|array',
            'actual_feeding_time.*.time' => 'required_with:actual_feeding_time|string',
            'actual_feeding_time.*.percentage' => 'required_with:actual_feeding_time|numeric|min:0|max:100',
            'actual_quantity' => 'nullable|numeric|min:0',
            'feeding_date' => 'required|date',
            'status' => 'sometimes|in:scheduled,completed,missed,late',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $perBirdGrams = (float) ($request->actual_quantity ?? 0);
        $headCount = $flock ? FeedingDayService::flockHeadCount($flock) : 1;
        $feedKg = $perBirdGrams > 0 ? round(($perBirdGrams * $headCount) / 1000, 3) : null;

        [$item, $inventoryWarning] = DB::transaction(function () use ($request, $farmId, $flock, $feedKg) {
            return app(FeedingBatchScheduleItemService::class)->createWithInventory([
                'feeding_batch_schedule_id' => $request->feeding_batch_schedule_id,
                'feeding_schedule_item_id' => $request->feeding_schedule_item_id,
                'actual_feeding_time' => $request->actual_feeding_time,
                'actual_quantity' => $request->actual_quantity,
                'actual_total_kg' => $feedKg,
                'feeding_date' => $request->feeding_date,
                'status' => $request->input('status', 'scheduled'),
            ], $farmId, $flock);
        });

        if ($inventoryWarning) {
            $item->inventory_note = $inventoryWarning;
        }

        return $this->sendResponse($item, 'Feeding batch schedule item created successfully', 201);
    }

    public function show($farm, $id)
    {
        $item = FeedingBatchScheduleItem::with(['batchSchedule', 'scheduleItem'])->findOrFail($id);
        $farmId = $item->batchSchedule && $item->batchSchedule->flock ? $item->batchSchedule->flock->farm_id : null;
        if ($farmId && !$this->canViewFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this feeding batch schedule item');
        }
        return $this->sendResponse($item, 'Feeding batch schedule item retrieved successfully');
    }

    public function update(Request $request, $farm, $id)
    {
        $item = FeedingBatchScheduleItem::findOrFail($id);
        $farmId = $item->batchSchedule && $item->batchSchedule->flock ? $item->batchSchedule->flock->farm_id : null;
        if ($farmId && !$this->canUpdateFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this feeding batch schedule item');
        }

        $flock = $item->batchSchedule?->flock;
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'feeding_batch_schedule_id' => 'sometimes|required|exists:feeding_batch_schedules,id',
            'feeding_schedule_item_id' => 'sometimes|required|exists:feeding_schedule_items,id',
            'actual_feeding_time' => 'nullable|array',
            'actual_feeding_time.*.time' => 'required_with:actual_feeding_time|string',
            'actual_feeding_time.*.percentage' => 'required_with:actual_feeding_time|numeric|min:0|max:100',
            'actual_quantity' => 'nullable|numeric|min:0',
            'feeding_date' => 'sometimes|required|date',
            'status' => 'sometimes|in:scheduled,completed,missed,late',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $item->update($request->only([
            'feeding_batch_schedule_id',
            'feeding_schedule_item_id',
            'actual_feeding_time',
            'actual_quantity',
            'feeding_date',
            'status',
        ]));
        return $this->sendResponse($item, 'Feeding batch schedule item updated successfully');
    }

    public function destroy($farm, $id)
    {
        $item = FeedingBatchScheduleItem::findOrFail($id);
        $farmId = $item->batchSchedule && $item->batchSchedule->flock ? $item->batchSchedule->flock->farm_id : null;
        if ($farmId && !$this->canDeleteFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this feeding batch schedule item');
        }

        $flock = $item->batchSchedule?->flock;
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $item->delete();
        return $this->sendResponse(null, 'Feeding batch schedule item deleted successfully');
    }

    /**
     * Get a feeding batch schedule item for a specific flock (batch) on a specific date.
     */
    public function getByBatchAndDate(Request $request, $farm, $flockId)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $flock = \App\Models\Flock::find($flockId);

        if (!$flock) {
            return $this->sendError('Flock not found', [], 404);
        }

        if (!$this->canViewFarmSchedules(auth()->user(), $flock->farm_id)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding batch schedule items');
        }

        $batchSchedule = \App\Models\FeedingBatchSchedule::where('flock_id', $flockId)->first();

        if (!$batchSchedule) {
            return $this->sendResponse(null, 'No feeding batch schedule found for this flock');
        }

        $item = FeedingBatchScheduleItem::with(['batchSchedule', 'scheduleItem'])
            ->where('feeding_batch_schedule_id', $batchSchedule->id)
            ->whereDate('feeding_date', $request->date)
            ->first();

        if ($item) {
            return $this->sendResponse($item, 'Feeding batch schedule item retrieved successfully');
        }

        $batchSchedule->load('schedule.items');
        $feedingDay = FeedingDayService::feedingDayForDate($flock, $request->date);
        $scheduleItem = app(FeedingScheduleRangeService::class)
            ->resolveForDay($batchSchedule->schedule, $feedingDay);

        if (!$scheduleItem) {
            return $this->sendResponse(null, 'No feeding batch schedule item found for this date');
        }

        return $this->sendResponse([
            'id' => null,
            'feeding_batch_schedule_id' => $batchSchedule->id,
            'feeding_schedule_item_id' => $scheduleItem->id,
            'feeding_date' => $request->date,
            'actual_quantity' => null,
            'planned_quantity' => $scheduleItem->quantity,
            'feeding_day' => $feedingDay,
            'start_day' => $scheduleItem->start_day,
            'end_day' => $scheduleItem->end_day,
            'is_planned' => true,
            'schedule_item' => $scheduleItem,
        ], 'Planned feeding schedule item retrieved successfully');
    }

    /**
     * Preview missed feeding days for a batch schedule.
     */
    public function missedDays(Request $request, $farm, $batchId)
    {
        $batch = $this->resolveBatchForFarm($farm, $batchId);
        if ($batch instanceof \Illuminate\Http\JsonResponse) {
            return $batch;
        }

        $flock = $batch->flock;
        if (!$this->canViewFarmSchedules(auth()->user(), $flock->farm_id)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding batch schedule items');
        }

        $options = $request->only(['from_day', 'through_day']);
        $result = app(FeedingMissedScheduleService::class)->listMissedDays($batch, $flock, $options);

        return $this->sendResponse($result, 'Missed feeding days retrieved successfully');
    }

    /**
     * Bulk-implement all missed feeding days for a batch schedule.
     */
    public function implementMissed(Request $request, $farm, $batchId)
    {
        $batch = $this->resolveBatchForFarm($farm, $batchId);
        if ($batch instanceof \Illuminate\Http\JsonResponse) {
            return $batch;
        }

        $flock = $batch->flock;
        if (!$this->canCreateFarmSchedules(auth()->user(), $flock->farm_id)) {
            return $this->sendUnauthorizedError('You do not have permission to create feeding batch schedule items');
        }

        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'from_day' => 'sometimes|integer|min:1',
            'through_day' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:scheduled,completed,missed,late',
            'inventory_by_feed_type' => 'sometimes|array',
            'inventory_by_feed_type.*' => 'integer|exists:poultry_feed_inventories,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        try {
            $result = app(FeedingMissedScheduleService::class)->implementMissed(
                $batch,
                $flock,
                $request->only(['from_day', 'through_day', 'status', 'inventory_by_feed_type'])
            );
        } catch (\InvalidArgumentException $e) {
            return $this->sendValidationError($e->getMessage(), ['inventory_by_feed_type' => [$e->getMessage()]]);
        }

        return $this->sendResponse(
            $result,
            $result['created_count'] > 0
                ? "Implemented {$result['created_count']} missed feeding day(s) successfully"
                : 'No missed feeding days to implement',
            $result['created_count'] > 0 ? 201 : 200
        );
    }

    /**
     * Preview late backfill records that can be reverted.
     */
    public function revertibleDays(Request $request, $farm, $batchId)
    {
        $batch = $this->resolveBatchForFarm($farm, $batchId);
        if ($batch instanceof \Illuminate\Http\JsonResponse) {
            return $batch;
        }

        $flock = $batch->flock;
        if (!$this->canViewFarmSchedules(auth()->user(), $flock->farm_id)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding batch schedule items');
        }

        $result = app(FeedingMissedScheduleService::class)->listRevertibleDays(
            $batch,
            $flock,
            $request->only(['from_day', 'through_day'])
        );

        return $this->sendResponse($result, 'Revertible feeding days retrieved successfully');
    }

    /**
     * Revert bulk backfilled late feeding records and restore inventory.
     */
    public function revertMissed(Request $request, $farm, $batchId)
    {
        $batch = $this->resolveBatchForFarm($farm, $batchId);
        if ($batch instanceof \Illuminate\Http\JsonResponse) {
            return $batch;
        }

        $flock = $batch->flock;
        if (!$this->canCreateFarmSchedules(auth()->user(), $flock->farm_id)) {
            return $this->sendUnauthorizedError('You do not have permission to revert feeding batch schedule items');
        }

        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'from_day' => 'sometimes|integer|min:1',
            'through_day' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $result = app(FeedingMissedScheduleService::class)->revertMissed(
            $batch,
            $flock,
            $request->only(['from_day', 'through_day'])
        );

        return $this->sendResponse(
            $result,
            $result['reverted_count'] > 0
                ? "Reverted {$result['reverted_count']} late feeding backfill(s) successfully"
                : 'No late backfills to revert'
        );
    }

    /**
     * @return FeedingBatchSchedule|\Illuminate\Http\JsonResponse
     */
    private function resolveBatchForFarm($farmId, $batchId)
    {
        $batch = FeedingBatchSchedule::with(['flock', 'schedule.items'])->find($batchId);

        if (!$batch) {
            return $this->sendError('Feeding batch schedule not found', [], 404);
        }

        if ((int) $batch->farm_id !== (int) $farmId) {
            return $this->sendError('Feeding batch schedule does not belong to this farm', [], 404);
        }

        if (!$batch->flock) {
            return $this->sendError('Flock not found for this batch schedule', [], 404);
        }

        return $batch;
    }
} 