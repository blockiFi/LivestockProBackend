<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\FeedingBatchScheduleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeedingBatchScheduleItemController extends ApiController
{
    public function index()
    {
        $feedingBatchScheduleId = request('feeding_batch_schedule_id');
        $farmId = null;
        if ($feedingBatchScheduleId) {
            $feedingBatchSchedule = \App\Models\FeedingBatchSchedule::find($feedingBatchScheduleId);
            $flock = $feedingBatchSchedule ? $feedingBatchSchedule->flock : null;
            $farmId = $flock ? $flock->farm_id : null;
        }
        if ($farmId && !auth()->user()->can('view feeding batch schedule items', 'api', $farmId)) {
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
        if ($farmId && !auth()->user()->can('create feeding batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create feeding batch schedule items');
        }
        $validator = Validator::make($request->all(), [
            'feeding_batch_schedule_id' => 'required|exists:feeding_batch_schedules,id',
            'feeding_schedule_item_id' => 'required|exists:feeding_schedule_items,id',
            'actual_feeding_time' => 'nullable|array',
            'actual_feeding_time.*.time' => 'required_with:actual_feeding_time|string',
            'actual_feeding_time.*.percentage' => 'required_with:actual_feeding_time|numeric|min:0|max:100',
            'actual_quantity' => 'nullable|numeric|min:0',
            'status' => 'sometimes|in:scheduled,completed,missed,late',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $item = FeedingBatchScheduleItem::create($request->only([
            'feeding_batch_schedule_id',
            'feeding_schedule_item_id',
            'actual_feeding_time',
            'actual_quantity',
            'status',
        ]));
        return $this->sendResponse($item, 'Feeding batch schedule item created successfully', 201);
    }

    public function show($id)
    {
        $item = FeedingBatchScheduleItem::with(['batchSchedule', 'scheduleItem'])->findOrFail($id);
        $farmId = $item->batchSchedule && $item->batchSchedule->flock ? $item->batchSchedule->flock->farm_id : null;
        if ($farmId && !auth()->user()->can('view feeding batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this feeding batch schedule item');
        }
        return $this->sendResponse($item, 'Feeding batch schedule item retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $item = FeedingBatchScheduleItem::findOrFail($id);
        $farmId = $item->batchSchedule && $item->batchSchedule->flock ? $item->batchSchedule->flock->farm_id : null;
        if ($farmId && !auth()->user()->can('update feeding batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this feeding batch schedule item');
        }
        $validator = Validator::make($request->all(), [
            'feeding_batch_schedule_id' => 'sometimes|required|exists:feeding_batch_schedules,id',
            'feeding_schedule_item_id' => 'sometimes|required|exists:feeding_schedule_items,id',
            'actual_feeding_time' => 'nullable|array',
            'actual_feeding_time.*.time' => 'required_with:actual_feeding_time|string',
            'actual_feeding_time.*.percentage' => 'required_with:actual_feeding_time|numeric|min:0|max:100',
            'actual_quantity' => 'nullable|numeric|min:0',
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
            'status',
        ]));
        return $this->sendResponse($item, 'Feeding batch schedule item updated successfully');
    }

    public function destroy($id)
    {
        $item = FeedingBatchScheduleItem::findOrFail($id);
        $farmId = $item->batchSchedule && $item->batchSchedule->flock ? $item->batchSchedule->flock->farm_id : null;
        if ($farmId && !auth()->user()->can('delete feeding batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this feeding batch schedule item');
        }
        $item->delete();
        return $this->sendResponse(null, 'Feeding batch schedule item deleted successfully');
    }
} 