<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\ScheduleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ScheduleItemController extends ApiController
{
    public function index()
    {
        $scheduleId = request('schedule_id');
        $farmId = null;
        if ($scheduleId) {
            $schedule = \App\Models\Schedule::find($scheduleId);
            $farmId = $schedule ? $schedule->farm_id : null;
        }
        if ($farmId && !auth()->user()->can('view schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view schedule items');
        }
        $items = ScheduleItem::with(['schedule', 'medicationProduct', 'vaccineProduct', 'administrationMethods'])->paginate(15);
        return $this->sendResponse($items, 'Schedule items retrieved successfully');
    }

    public function store(Request $request)
    {
        $schedule = \App\Models\Schedule::find($request->schedule_id);
        $farmId = $schedule ? $schedule->farm_id : null;
        if ($farmId && !auth()->user()->can('create schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create schedule items');
        }
        $validator = Validator::make($request->all(), [
            'schedule_id' => 'required|exists:schedules,id',
            'age_days' => 'required|integer|min:0',
            'poultry_vaccine_id' => 'nullable|exists:poultry_vaccines,id',
            'poultry_medication_id' => 'nullable|exists:poultry_medications,id',
            'name' => 'required|string|max:255',
            'dose' => 'required|integer|min:1',
            'withdrawal_period_days' => 'nullable|integer|min:0',
            'storage_instructions' => 'nullable|string',
            'description' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $item = ScheduleItem::create($request->only([
            'schedule_id',
            'age_days',
            'poultry_vaccine_id',
            'poultry_medication_id',
            'name',
            'dose',
            'withdrawal_period_days',
            'storage_instructions',
            'description',
        ]));
        return $this->sendResponse($item, 'Schedule item created successfully', 201);
    }

    public function show($id)
    {
        $item = ScheduleItem::with(['schedule', 'medicationProduct', 'vaccineProduct', 'administrationMethods'])->findOrFail($id);
        $farmId = $item->schedule ? $item->schedule->farm_id : null;
        if ($farmId && !auth()->user()->can('view schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this schedule item');
        }
        return $this->sendResponse($item, 'Schedule item retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $item = ScheduleItem::findOrFail($id);
        $farmId = $item->schedule ? $item->schedule->farm_id : null;
        if ($farmId && !auth()->user()->can('update schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this schedule item');
        }
        $validator = Validator::make($request->all(), [
            'schedule_id' => 'sometimes|required|exists:schedules,id',
            'age_days' => 'sometimes|required|integer|min:0',
            'poultry_vaccine_id' => 'nullable|exists:poultry_vaccines,id',
            'poultry_medication_id' => 'nullable|exists:poultry_medications,id',
            'name' => 'sometimes|required|string|max:255',
            'dose' => 'sometimes|required|integer|min:1',
            'withdrawal_period_days' => 'nullable|integer|min:0',
            'storage_instructions' => 'nullable|string',
            'description' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $item->update($request->only([
            'schedule_id',
            'age_days',
            'poultry_vaccine_id',
            'poultry_medication_id',
            'name',
            'dose',
            'withdrawal_period_days',
            'storage_instructions',
            'description',
        ]));
        return $this->sendResponse($item, 'Schedule item updated successfully');
    }

    public function destroy($id)
    {
        $item = ScheduleItem::findOrFail($id);
        $farmId = $item->schedule ? $item->schedule->farm_id : null;
        if ($farmId && !auth()->user()->can('delete schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this schedule item');
        }
        $item->delete();
        return $this->sendResponse(null, 'Schedule item deleted successfully');
    }
} 