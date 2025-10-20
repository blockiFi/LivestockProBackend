<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ScheduleController extends ApiController
{
    public function index()
    {
        $farmId = request('farm_id');
        $type = request('type');
        if (!in_array($type, ['medication', 'vaccination'])) {
            return $this->sendValidationError('Invalid type. Type must be either medication or vaccination.');
        }
        if ($farmId && !auth()->user()->can('view schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view schedules');
        }
        $schedules = Schedule::with(['items', 'batchSchedules', 'farm'])
            ->where('schedule_type', $type)
            ->where(function ($query) use ($farmId) {
                $query->where('type', 'default');
                if ($farmId) {
                    $query->orWhere('farm_id', $farmId);
                }
            })
            ->paginate(5);
        return $this->sendResponse($schedules, 'Schedules retrieved successfully');
    }

    public function store(Request $request)
    {
        $farmId = $request->farm_id;
        if ($farmId && !auth()->user()->can('create schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create schedules');
        }
        $validator = Validator::make($request->all(), [
            'schedule_type' => 'required|in:medication,vaccination',
            'poultry_type_id' => 'required|exists:poultry_types,id',
           
            'farm_id' => 'nullable|exists:farms,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $data = $request->only(['schedule_type', 'poultry_type_id', 'farm_id', 'name', 'description']);
        $data['type'] = 'user';
        $schedule = Schedule::create($data);
        return $this->sendResponse($schedule, 'Schedule created successfully', 201);
    }

    public function show($id)
    {
        $schedule = Schedule::with(['items', 'batchSchedules', 'farm'])->findOrFail($id);
        $farmId = $schedule->farm_id;
        // Allow access if it's a default schedule (type = 'default'), otherwise check permission

        if ($schedule->type !== 'default' && $farmId && !auth()->user()->can('view schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this schedule');
        }
        $schedule = Schedule::with(['items', 'farm'])->findOrFail($id);
        $farmId = $schedule->farm_id;
        if ($farmId && !auth()->user()->can('view schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this schedule');
        }
        return $this->sendResponse($schedule, 'Schedule retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $schedule = Schedule::findOrFail($id);
        if ($schedule->type === 'default') {
            return $this->sendUnauthorizedError('Default schedules cannot be updated');
        }
        $schedule = Schedule::findOrFail($id);
        $farmId = $schedule->farm_id;
        if ($farmId && !auth()->user()->can('update schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this schedule');
        }
        $validator = Validator::make($request->all(), [
            'schedule_type' => 'sometimes|required|in:medication,vaccination',
            'poultry_type_id' => 'sometimes|required|exists:poultry_types,id',
            'type' => 'sometimes|required|in:default,user',
            'farm_id' => 'nullable|exists:farms,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $schedule->update($request->only(['schedule_type', 'poultry_type_id', 'type', 'farm_id', 'name', 'description']));
        return $this->sendResponse($schedule, 'Schedule updated successfully');
    }

    public function destroy($id)
    {
        $schedule = Schedule::findOrFail($id);
        if ($schedule->type === 'default') {
            return $this->sendUnauthorizedError('Default schedules cannot be deleted');
        }
        $schedule = Schedule::findOrFail($id);
        $farmId = $schedule->farm_id;
        if ($farmId && !auth()->user()->can('delete schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this schedule');
        }
        $schedule->delete();
        return $this->sendResponse(null, 'Schedule deleted successfully');
    }
} 