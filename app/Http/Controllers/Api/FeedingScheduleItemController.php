<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\FeedingScheduleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeedingScheduleItemController extends ApiController
{
    public function index()
    {
        $feedingScheduleId = request('feeding_schedule_id');
        $farmId = null;
        if ($feedingScheduleId) {
            $feedingSchedule = \App\Models\FeedingSchedule::find($feedingScheduleId);
            $farmId = $feedingSchedule ? $feedingSchedule->farm_id : null;
        }
        if ($farmId && !auth()->user()->can('view feeding schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding schedule items');
        }
        $items = FeedingScheduleItem::with(['schedule', 'feedType'])->paginate(15);
        return $this->sendResponse($items, 'Feeding schedule items retrieved successfully');
    }

    public function store(Request $request)
    {
        $feedingSchedule = \App\Models\FeedingSchedule::find($request->feeding_schedule_id);
        $farmId = $feedingSchedule ? $feedingSchedule->farm_id : null;
        if ($farmId && !auth()->user()->can('create feeding schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create feeding schedule items');
        }
        $validator = Validator::make($request->all(), [
            'feeding_schedule_id' => 'required|exists:feeding_schedules,id',
            'feed_type_id' => 'required|exists:poultry_feed_types,id',
            'feeding_times' => 'required|array',
            'feeding_times.*.time' => 'required|string',
            'feeding_times.*.percentage' => 'required|numeric|min:0|max:100',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $item = FeedingScheduleItem::create([
            'feeding_schedule_id' => $request->feeding_schedule_id,
            'feed_type_id' => $request->feed_type_id,
            'feeding_times' => $request->feeding_times,
        ]);
        return $this->sendResponse($item, 'Feeding schedule item created successfully', 201);
    }

    public function show($id)
    {
        $item = FeedingScheduleItem::with(['schedule', 'feedType'])->findOrFail($id);
        $farmId = $item->schedule ? $item->schedule->farm_id : null;
        if ($farmId && !auth()->user()->can('view feeding schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this feeding schedule item');
        }
        return $this->sendResponse($item, 'Feeding schedule item retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $item = FeedingScheduleItem::findOrFail($id);
        $farmId = $item->schedule ? $item->schedule->farm_id : null;
        if ($farmId && !auth()->user()->can('update feeding schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this feeding schedule item');
        }
        $validator = Validator::make($request->all(), [
            'feeding_schedule_id' => 'sometimes|required|exists:feeding_schedules,id',
            'feed_type_id' => 'sometimes|required|exists:poultry_feed_types,id',
            'feeding_times' => 'sometimes|required|array',
            'feeding_times.*.time' => 'required_with:feeding_times|string',
            'feeding_times.*.percentage' => 'required_with:feeding_times|numeric|min:0|max:100',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $item->update($request->only(['feeding_schedule_id', 'feed_type_id', 'feeding_times']));
        return $this->sendResponse($item, 'Feeding schedule item updated successfully');
    }

    public function destroy($id)
    {
        $item = FeedingScheduleItem::findOrFail($id);
        $farmId = $item->schedule ? $item->schedule->farm_id : null;
        if ($farmId && !auth()->user()->can('delete feeding schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this feeding schedule item');
        }
        $item->delete();
        return $this->sendResponse(null, 'Feeding schedule item deleted successfully');
    }
} 