<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\FeedingBatchSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeedingBatchScheduleController extends ApiController
{
    public function index()
    {
        $flockId = request('flock_id');
        $farmId = null;
        if ($flockId) {
            $flock = \App\Models\Flock::find($flockId);
            $farmId = $flock ? $flock->farm_id : null;
        }
        if ($farmId && !auth()->user()->can('view feeding batch schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding batch schedules');
        }
        $schedules = FeedingBatchSchedule::with(['flock', 'schedule'])->paginate(15);
        return $this->sendResponse($schedules, 'Feeding batch schedules retrieved successfully');
    }

    public function store(Request $request)
    {
        $flock = \App\Models\Flock::find($request->flock_id);
        $farmId = $flock ? $flock->farm_id : null;
        if ($farmId && !auth()->user()->can('create feeding batch schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create feeding batch schedules');
        }
        $validator = Validator::make($request->all(), [
            'flock_id' => 'required|exists:flocks,id',
            'feeding_schedule_id' => 'required|exists:feeding_schedules,id',
            'status' => 'sometimes|in:scheduled,in_progress,completed,cancelled',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $schedule = FeedingBatchSchedule::create($request->only(['flock_id', 'feeding_schedule_id', 'status']));
        return $this->sendResponse($schedule, 'Feeding batch schedule created successfully', 201);
    }

    public function show($id)
    {
        $schedule = FeedingBatchSchedule::with(['flock', 'schedule'])->findOrFail($id);
        $farmId = $schedule->flock ? $schedule->flock->farm_id : null;
        if ($farmId && !auth()->user()->can('view feeding batch schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this feeding batch schedule');
        }
        return $this->sendResponse($schedule, 'Feeding batch schedule retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $schedule = FeedingBatchSchedule::findOrFail($id);
        $farmId = $schedule->flock ? $schedule->flock->farm_id : null;
        if ($farmId && !auth()->user()->can('update feeding batch schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this feeding batch schedule');
        }
        $validator = Validator::make($request->all(), [
            'flock_id' => 'sometimes|required|exists:flocks,id',
            'feeding_schedule_id' => 'sometimes|required|exists:feeding_schedules,id',
            'status' => 'sometimes|in:scheduled,in_progress,completed,cancelled',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $schedule->update($request->only(['flock_id', 'feeding_schedule_id', 'status']));
        return $this->sendResponse($schedule, 'Feeding batch schedule updated successfully');
    }

    public function destroy($id)
    {
        $schedule = FeedingBatchSchedule::findOrFail($id);
        $farmId = $schedule->flock ? $schedule->flock->farm_id : null;
        if ($farmId && !auth()->user()->can('delete feeding batch schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this feeding batch schedule');
        }
        $schedule->delete();
        return $this->sendResponse(null, 'Feeding batch schedule deleted successfully');
    }
} 