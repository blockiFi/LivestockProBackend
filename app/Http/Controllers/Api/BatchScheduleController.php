<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Concerns\ChecksScheduleAccess;
use App\Models\BatchSchedule;
use App\Models\Flock;
use App\Models\Schedule;
use App\Services\MedVacBatchScheduleItemGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BatchScheduleController extends ApiController
{
    use ChecksScheduleAccess;

    public function index()
    {
        $farmId = request('farm_id') ?? request()->route('farm');
        if ($farmId && !$this->canViewFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view batch schedules');
        }
        $schedules = BatchSchedule::with(['farm', 'schedule', 'items'])->paginate(15);
        return $this->sendResponse($schedules, 'Batch schedules retrieved successfully');
    }

    public function store(Request $request)
    {
        $type = (string) ($request->route('type') ?? $request->input('type') ?? '');
        if (!in_array($type, ['medication', 'vaccination'], true)) {
            return $this->sendValidationError('Invalid schedule type. Must be medication or vaccination.');
        }

        $routeFarmId = $request->route('farm');
        $farmId = is_object($routeFarmId) ? (int) $routeFarmId->id : (int) ($routeFarmId ?: $request->input('farm_id'));

        $validator = Validator::make($request->all(), [
            'farm_id' => 'sometimes|exists:farms,id',
            'flock_id' => 'required|exists:flocks,id',
            'schedule_id' => 'required|exists:schedules,id',
            'status' => 'sometimes|in:active,inactive',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $flock = Flock::with('poultryType')->find($request->flock_id);
        if (!$flock) {
            return $this->sendError('Flock not found', [], 404);
        }

        $farmId = $farmId ?: (int) $flock->farm_id;
        if ((int) $flock->farm_id !== (int) $farmId) {
            return $this->sendError('Flock does not belong to this farm', [], 422);
        }

        if (!$this->canCreateFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create batch schedules');
        }

        if ($inactive = $this->ensureFlockIsActive($flock)) {
            return $inactive;
        }

        $template = Schedule::with('items')->find($request->schedule_id);
        if (!$template) {
            return $this->sendError('Schedule template not found', [], 404);
        }

        if ($template->schedule_type !== $type) {
            return $this->sendValidationError('Validation failed', [
                'schedule_id' => ["Selected schedule is not a {$type} schedule."],
            ]);
        }

        if ($template->farm_id && (int) $template->farm_id !== (int) $farmId) {
            return $this->sendError('Schedule template does not belong to this farm', [], 422);
        }

        if (
            $template->poultry_type_id
            && $flock->poultry_type_id
            && (int) $template->poultry_type_id !== (int) $flock->poultry_type_id
        ) {
            return $this->sendValidationError('Validation failed', [
                'schedule_id' => ['Schedule poultry type does not match this flock.'],
            ]);
        }

        $existing = BatchSchedule::where('flock_id', $flock->id)
            ->where('farm_id', $farmId)
            ->where(function ($q) {
                $q->where('status', 'active')->orWhereNull('status');
            })
            ->whereHas('schedule', function ($q) use ($type) {
                $q->where('schedule_type', $type);
            })
            ->first();

        $generator = app(MedVacBatchScheduleItemGenerator::class);

        if ($existing) {
            if (!$this->canUpdateFarmSchedules(auth()->user(), $farmId)) {
                return $this->sendUnauthorizedError('You do not have permission to reassign batch schedules');
            }

            if ((int) $existing->schedule_id === (int) $template->id) {
                return $this->sendResponse(
                    $existing->load(['farm', 'schedule.items', 'items.scheduleItem']),
                    ucfirst($type) . ' schedule already assigned',
                    200
                );
            }

            $existing->items()->delete();
            $existing->fill([
                'schedule_id' => $template->id,
                'status' => $request->input('status', 'active'),
            ]);
            $existing->save();

            $generator->generateForBatchSchedule($existing->fresh(['schedule.items', 'flock']));

            return $this->sendResponse(
                $existing->fresh()->load(['farm', 'schedule.items', 'items.scheduleItem']),
                ucfirst($type) . ' schedule reassigned successfully',
                200
            );
        }

        $batchSchedule = new BatchSchedule([
            'farm_id' => $farmId,
            'flock_id' => $flock->id,
            'schedule_id' => $template->id,
        ]);
        $batchSchedule->status = $request->input('status', 'active');
        $batchSchedule->save();

        $generator->generateForBatchSchedule($batchSchedule->fresh(['schedule.items', 'flock']));

        return $this->sendResponse(
            $batchSchedule->fresh()->load(['farm', 'schedule.items', 'items.scheduleItem']),
            ucfirst($type) . ' batch schedule created successfully',
            201
        );
    }

    public function show($farm, $type, $id)
    {
        $schedule = BatchSchedule::with(['farm', 'schedule', 'items'])->findOrFail($id);
        $farmId = $schedule->farm_id;
        if ($farmId && !$this->canViewFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this batch schedule');
        }
        return $this->sendResponse($schedule, 'Batch schedule retrieved successfully');
    }

    public function update(Request $request, $farm, $type, $id)
    {
        $schedule = BatchSchedule::findOrFail($id);
        $farmId = $schedule->farm_id;
        if ($farmId && !$this->canUpdateFarmSchedules(auth()->user(), $farmId)) {
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

        $scheduleIdChanged = $request->filled('schedule_id')
            && (int) $request->schedule_id !== (int) $schedule->schedule_id;

        $schedule->fill($request->only(['farm_id', 'flock_id', 'schedule_id', 'status']));
        $schedule->save();

        if ($scheduleIdChanged) {
            $schedule->items()->delete();
            app(MedVacBatchScheduleItemGenerator::class)
                ->generateForBatchSchedule($schedule->fresh(['schedule.items', 'flock']));
        }

        return $this->sendResponse(
            $schedule->fresh()->load(['farm', 'schedule.items', 'items.scheduleItem']),
            'Batch schedule updated successfully'
        );
    }

    public function destroy($farm, $type, $id)
    {
        $schedule = BatchSchedule::findOrFail($id);
        $farmId = $schedule->farm_id;
        if ($farmId && !$this->canDeleteFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this batch schedule');
        }
        $schedule->delete();
        return $this->sendResponse(null, 'Batch schedule deleted successfully');
    }
}
