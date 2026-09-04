<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Concerns\ChecksScheduleAccess;
use App\Models\FeedingBatchSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeedingBatchScheduleController extends ApiController
{
    use ChecksScheduleAccess;

    public function index()
    {
        $flockId = request('flock_id');
        $farmId = null;
        if ($flockId) {
            $flock = \App\Models\Flock::find($flockId);
            $farmId = $flock ? $flock->farm_id : null;
        }
        if ($farmId && !$this->canViewFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding batch schedules');
        }
        $schedules = FeedingBatchSchedule::with(['flock', 'schedule'])->paginate(15);
        return $this->sendResponse($schedules, 'Feeding batch schedules retrieved successfully');
    }

    public function store(Request $request)
    {
        $flock = \App\Models\Flock::find($request->flock_id);
        $farmId = $flock ? $flock->farm_id : null;
        if ($farmId && !$this->canCreateFarmSchedules(auth()->user(), $farmId)) {
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

        if ($flock && ($inactive = $this->ensureFlockIsActive($flock))) {
            return $inactive;
        }

        $existing = FeedingBatchSchedule::where('flock_id', $request->flock_id)
            ->whereNotIn('status', ['cancelled'])
            ->first();

        // Reassign: swap the template on an existing batch assignment (new flocks included).
        if ($existing) {
            if (!$this->canUpdateFarmSchedules(auth()->user(), $farmId)) {
                return $this->sendUnauthorizedError('You do not have permission to reassign feeding batch schedules');
            }

            if ((int) $existing->feeding_schedule_id === (int) $request->feeding_schedule_id) {
                return $this->sendResponse(
                    $existing->load(['flock', 'schedule.items.feedType', 'items.scheduleItem']),
                    'Feeding batch schedule already assigned',
                    200
                );
            }

            $existing->items()->delete();
            $existing->update([
                'feeding_schedule_id' => $request->feeding_schedule_id,
                'status' => $request->input('status', 'scheduled'),
            ]);

            return $this->sendResponse(
                $existing->fresh()->load(['flock', 'schedule.items.feedType', 'items.scheduleItem']),
                'Feeding batch schedule reassigned successfully',
                200
            );
        }

        $schedule = FeedingBatchSchedule::create([
            'farm_id' => $farmId,
            'flock_id' => $request->flock_id,
            'feeding_schedule_id' => $request->feeding_schedule_id,
            'status' => $request->input('status', 'scheduled'),
        ]);

        return $this->sendResponse(
            $schedule->load(['flock', 'schedule.items.feedType']),
            'Feeding batch schedule created successfully',
            201
        );
    }

    public function show($farm, $id)
    {
        $schedule = FeedingBatchSchedule::with(['flock', 'schedule'])->findOrFail($id);
        $farmId = $schedule->flock ? $schedule->flock->farm_id : null;
        if ($farmId && !$this->canViewFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this feeding batch schedule');
        }
        return $this->sendResponse($schedule, 'Feeding batch schedule retrieved successfully');
    }

    public function update(Request $request, $farm, $id)
    {
        $schedule = FeedingBatchSchedule::findOrFail($id);
        $farmId = $schedule->flock ? $schedule->flock->farm_id : null;
        if ($farmId && !$this->canUpdateFarmSchedules(auth()->user(), $farmId)) {
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

    public function destroy($farm, $id)
    {
        $schedule = FeedingBatchSchedule::findOrFail($id);
        $farmId = $schedule->flock ? $schedule->flock->farm_id : null;
        if ($farmId && !$this->canDeleteFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this feeding batch schedule');
        }
        $schedule->delete();
        return $this->sendResponse(null, 'Feeding batch schedule deleted successfully');
    }
} 