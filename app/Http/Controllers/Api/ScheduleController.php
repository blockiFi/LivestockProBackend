<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ScheduleController extends ApiController
{
    private function resolveFarmId(mixed $farm): ?int
    {
        if ($farm instanceof \App\Models\Farm) {
            return $farm->id;
        }

        return $farm !== null ? (int) $farm : null;
    }

    private function findScopedSchedule(mixed $farm, string $type, mixed $id): Schedule
    {
        if (! in_array($type, ['medication', 'vaccination'], true)) {
            abort(422, 'Invalid type. Type must be either medication or vaccination.');
        }

        $farmId = $this->resolveFarmId($farm);
        $scheduleId = $id instanceof Schedule ? $id->id : (int) $id;

        return Schedule::with(['items', 'batchSchedules', 'farm'])
            ->where('id', $scheduleId)
            ->where('schedule_type', $type)
            ->where(function ($query) use ($farmId) {
                $query->where('type', 'default');
                if ($farmId) {
                    $query->orWhere('farm_id', $farmId);
                }
            })
            ->firstOrFail();
    }

    public function index($paginated = null)
    {
        $farmId = request()->route('farm') ?? request('farm_id');
        $type = request()->route('type') ?? request('type');
        if (!in_array($type, ['medication', 'vaccination'])) {
            return $this->sendValidationError('Invalid type. Type must be either medication or vaccination.');
        }
        if ($farmId && !auth()->user()->can('view schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view schedules');
        }
        $query = Schedule::with(['items', 'batchSchedules', 'farm'])
            ->where('schedule_type', $type)
            ->where(function ($query) use ($farmId) {
                $query->where('type', 'default');
                if ($farmId) {
                    $query->orWhere('farm_id', $farmId);
                }
            });

        // Filter by poultry type if provided
        if (request()->has('poultry_type_id')) {
            $query->where('poultry_type_id', request('poultry_type_id'));
        }

        $explicitPagination = null;
        if (request()->has('pagination')) {
            $explicitPagination = filter_var(request('pagination'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } elseif (request()->has('paginate')) {
            $explicitPagination = filter_var(request('paginate'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $shouldPaginateFromQuery = filter_var(request('paginate', false), FILTER_VALIDATE_BOOLEAN);

        // Allow explicit `pagination=false` to override any route default that may enforce pagination
        if ($explicitPagination === false) {
            $schedules = $query->get();
        } elseif ($paginated || $shouldPaginateFromQuery || $explicitPagination === true) {
            $perPage = request('per_page') ?? request('perPage') ?? 5;
            $schedules = $query->paginate($perPage);
        } else {
            $schedules = $query->get();
        }
        return $this->sendResponse($schedules, 'Schedules retrieved successfully');
    }

    public function store(Request $request)
    {
        $farmId = $request->route('farm') ?? $request->farm_id;
        if ($farmId && !auth()->user()->can('create schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create schedules');
        }
        $routeType = $request->route('type');
        $scheduleType = $routeType ?? $request->input('schedule_type');
        if (!in_array($scheduleType, ['medication', 'vaccination'])) {
            return $this->sendValidationError('Invalid schedule_type. Must be medication or vaccination.');
        }
        $validator = Validator::make($request->all(), [
            // schedule_type comes from route or body
            'poultry_type_id' => 'required|exists:poultry_types,id',
            'feeding_type_id' => 'integer',
            'farm_id' => 'nullable|exists:farms,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            // items validation intentionally omitted here; created via separate endpoint
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $data = $request->only(['poultry_type_id', 'farm_id', 'name', 'description']);
        $data['schedule_type'] = $scheduleType;
        $data['type'] = 'user';
        $schedule = Schedule::create($data);
        return $this->sendResponse($schedule, 'Schedule created successfully', 201);
    }

    public function show($farm, string $type, $id)
    {
        $schedule = $this->findScopedSchedule($farm, $type, $id);
        $farmId = $schedule->farm_id;
        if ($schedule->type !== 'default' && $farmId && ! auth()->user()->can('view schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this schedule');
        }

        return $this->sendResponse($schedule->load(['items', 'farm']), 'Schedule retrieved successfully');
    }

    public function update(Request $request, $farm, string $type, $id)
    {
        $schedule = $this->findScopedSchedule($farm, $type, $id);
        if ($schedule->type === 'default') {
            return $this->sendUnauthorizedError('Default schedules cannot be updated');
        }
        $farmId = $schedule->farm_id;
        if ($farmId && ! auth()->user()->can('update schedules', 'api', $farmId)) {
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

    public function destroy($farm, string $type, $id)
    {
        $schedule = $this->findScopedSchedule($farm, $type, $id);
        if ($schedule->type === 'default') {
            return $this->sendUnauthorizedError('Default schedules cannot be deleted');
        }
        $farmId = $schedule->farm_id;
        if ($farmId && ! auth()->user()->can('delete schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this schedule');
        }
        $schedule->delete();
        return $this->sendResponse(null, 'Schedule deleted successfully');
    }
}