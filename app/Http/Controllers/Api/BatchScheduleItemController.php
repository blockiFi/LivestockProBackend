<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\BatchScheduleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BatchScheduleItemController extends ApiController
{
    public function index()
    {
        $batchScheduleId = request('batch_schedule_id');
        $farmId = null;
        if ($batchScheduleId) {
            $batchSchedule = \App\Models\BatchSchedule::find($batchScheduleId);
            $farmId = $batchSchedule ? $batchSchedule->farm_id : null;
        }
        if ($farmId && !auth()->user()->can('view batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view batch schedule items');
        }
        $items = BatchScheduleItem::with(['batchSchedule', 'scheduleItem'])->paginate(15);
        return $this->sendResponse($items, 'Batch schedule items retrieved successfully');
    }

    public function store(Request $request)
    {
        $batchSchedule = \App\Models\BatchSchedule::find($request->batch_schedule_id);
        $farmId = $batchSchedule ? $batchSchedule->farm_id : null;
        if ($farmId && !auth()->user()->can('create batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create batch schedule items');
        }
        $validator = Validator::make($request->all(), [
            'batch_schedule_id' => 'required|exists:batch_schedules,id',
            'schedule_item_id' => 'required|exists:schedule_items,id',
            'status' => 'sometimes|in:scheduled,completed,missed,late',
            'scheduled_date' => 'required|date',
            'actual_date' => 'nullable|date',
            'administered_by' => 'nullable|string|max:255',
            'poultry_vaccine_product_id' => 'required|exists:poultry_vaccine_products,id',
            'vaccine_product_batch_id' => 'nullable|exists:vaccine_product_batches,id',
            'poultry_medication_id' => 'required|exists:poultry_medications,id',
            'dosage' => 'nullable|integer|min:0',
            'quantity' => 'nullable|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'administration_method_id' => 'nullable|exists:administration_methods,id',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $item = BatchScheduleItem::create($request->only([
            'batch_schedule_id',
            'schedule_item_id',
            'status',
            'scheduled_date',
            'actual_date',
            'administered_by',
            'poultry_vaccine_product_id',
            'vaccine_product_batch_id',
            'poultry_medication_id',
            'dosage',
            'quantity',
            'cost',
            'notes',
            'administration_method_id',
        ]));
        return $this->sendResponse($item, 'Batch schedule item created successfully', 201);
    }

    public function show($id)
    {
        $item = BatchScheduleItem::with(['batchSchedule', 'scheduleItem'])->findOrFail($id);
        $farmId = $item->batchSchedule ? $item->batchSchedule->farm_id : null;
        if ($farmId && !auth()->user()->can('view batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this batch schedule item');
        }
        return $this->sendResponse($item, 'Batch schedule item retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $item = BatchScheduleItem::findOrFail($id);
        $farmId = $item->batchSchedule ? $item->batchSchedule->farm_id : null;
        if ($farmId && !auth()->user()->can('update batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this batch schedule item');
        }
        $validator = Validator::make($request->all(), [
            'batch_schedule_id' => 'sometimes|required|exists:batch_schedules,id',
            'schedule_item_id' => 'sometimes|required|exists:schedule_items,id',
            'status' => 'sometimes|in:scheduled,completed,missed,late',
            'scheduled_date' => 'sometimes|required|date',
            'actual_date' => 'nullable|date',
            'administered_by' => 'nullable|string|max:255',
            'poultry_vaccine_product_id' => 'sometimes|required|exists:poultry_vaccine_products,id',
            'vaccine_product_batch_id' => 'nullable|exists:vaccine_product_batches,id',
            'poultry_medication_id' => 'sometimes|required|exists:poultry_medications,id',
            'dosage' => 'nullable|integer|min:0',
            'quantity' => 'nullable|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'administration_method_id' => 'nullable|exists:administration_methods,id',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $item->update($request->only([
            'batch_schedule_id',
            'schedule_item_id',
            'status',
            'scheduled_date',
            'actual_date',
            'administered_by',
            'poultry_vaccine_product_id',
            'vaccine_product_batch_id',
            'poultry_medication_id',
            'dosage',
            'quantity',
            'cost',
            'notes',
            'administration_method_id',
        ]));
        return $this->sendResponse($item, 'Batch schedule item updated successfully');
    }

    public function destroy($id)
    {
        $item = BatchScheduleItem::findOrFail($id);
        $farmId = $item->batchSchedule ? $item->batchSchedule->farm_id : null;
        if ($farmId && !auth()->user()->can('delete batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this batch schedule item');
        }
        $item->delete();
        return $this->sendResponse(null, 'Batch schedule item deleted successfully');
    }
} 