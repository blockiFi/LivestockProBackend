<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\FeedingSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeedingScheduleController extends ApiController
{
    public function index()
    {
        $farmId = request('farm_id');
        if ($farmId && !auth()->user()->can('view feeding schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding schedules');
        }
        $schedules = FeedingSchedule::with('items')->paginate(15);
        return $this->sendResponse($schedules, 'Feeding schedules retrieved successfully');
    }

    public function store(Request $request)
    {
        $farmId = $request->farm_id;
        if ($farmId && !auth()->user()->can('create feeding schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create feeding schedules');
        }
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $schedule = FeedingSchedule::create($request->only(['title', 'description', 'start_date', 'end_date']));
        return $this->sendResponse($schedule, 'Feeding schedule created successfully', 201);
    }

    public function show($id)
    {
        $schedule = FeedingSchedule::with('items')->findOrFail($id);
        $farmId = $schedule->farm_id ?? null;
        if ($farmId && !auth()->user()->can('view feeding schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this feeding schedule');
        }
        return $this->sendResponse($schedule, 'Feeding schedule retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $schedule = FeedingSchedule::findOrFail($id);
        $farmId = $schedule->farm_id ?? null;
        if ($farmId && !auth()->user()->can('update feeding schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this feeding schedule');
        }
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $schedule->update($request->only(['title', 'description', 'start_date', 'end_date']));
        return $this->sendResponse($schedule, 'Feeding schedule updated successfully');
    }

    public function destroy($id)
    {
        $schedule = FeedingSchedule::findOrFail($id);
        $farmId = $schedule->farm_id ?? null;
        if ($farmId && !auth()->user()->can('delete feeding schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this feeding schedule');
        }
        $schedule->delete();
        return $this->sendResponse(null, 'Feeding schedule deleted successfully');
    }
} 