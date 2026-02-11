<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\BatchSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BatchScheduleController extends ApiController
{
    public function index()
    {
        $farmId = request('farm_id');
        if ($farmId && !auth()->user()->can('view batch schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view batch schedules');
        }
        $schedules = BatchSchedule::with(['farm', 'schedule', 'items'])->paginate(15);
        return $this->sendResponse($schedules, 'Batch schedules retrieved successfully');
    }

    public function store(Request $request)
    {
        $farmId = $request->farm_id;
        if ($farmId && !auth()->user()->can('create batch schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create batch schedules');
        }
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|exists:farms,id',
            'flock_id' => 'required|exists:flocks,id',
            'schedule_id' => 'required|exists:schedules,id',
            'status' => 'sometimes|in:active,inactive',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $schedule = BatchSchedule::create($request->only(['farm_id', 'flock_id', 'schedule_id', 'status']));
        return $this->sendResponse($schedule, 'Batch schedule created successfully', 201);
    }

    public function show($id)
    {
        $schedule = BatchSchedule::with(['farm', 'schedule', 'items'])->findOrFail($id);
        $farmId = $schedule->farm_id;
        if ($farmId && !auth()->user()->can('view batch schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this batch schedule');
        }
        return $this->sendResponse($schedule, 'Batch schedule retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $schedule = BatchSchedule::findOrFail($id);
        $farmId = $schedule->farm_id;
        if ($farmId && !auth()->user()->can('update batch schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this batch schedule');
        }
        $validator = Validator::make($request->all(), [
            'farm_id' => 'sometimes|required|exists:farms,id',
            'flock_id' => 'sometimes|required|exists:flocks,id',
            'schedule_id' => 'sometimes|required|exists:schedules,id',
            'status' => 'sometimes|in:active,inactive',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $schedule->update($request->only(['farm_id', 'flock_id', 'schedule_id', 'status']));
        return $this->sendResponse($schedule, 'Batch schedule updated successfully');
    }
    
    public function destroy($id)
    {
        $schedule = BatchSchedule::findOrFail($id);
        $farmId = $schedule->farm_id;
        if ($farmId && !auth()->user()->can('delete batch schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this batch schedule');
        }
        $schedule->delete();
        return $this->sendResponse(null, 'Batch schedule deleted successfully');
    }
} 