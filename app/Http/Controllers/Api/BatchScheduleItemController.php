<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\BatchSchedule;
use App\Models\BatchScheduleItem;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockExpenditure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

class BatchScheduleItemController extends ApiController
{
    protected function authorizeFarm(Farm $farm, string $permission): ?\Illuminate\Http\JsonResponse
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (! auth()->user()->hasPermissionTo($permission, 'api', $farm)) {
            return $this->sendUnauthorizedError("Unauthorized to {$permission} batch schedule items");
        }

        return null;
    }

    public function index($farmId, $type = null)
    {
        $farm = Farm::findOrFail($farmId);
        if ($response = $this->authorizeFarm($farm, 'view flocks')) {
            return $response;
        }

        $batchScheduleId = request('batch_schedule_id');
        $query = BatchScheduleItem::with(['batchSchedule', 'scheduleItem']);

        if ($batchScheduleId) {
            $query->where('batch_schedule_id', $batchScheduleId);
        }

        $items = $query->paginate(15);

        return $this->sendResponse($items, 'Batch schedule items retrieved successfully');
    }

    public function store(Request $request, $farmId, $type = null)
    {
        $farm = Farm::findOrFail($farmId);
        if ($response = $this->authorizeFarm($farm, 'update flocks')) {
            return $response;
        }

        $scheduleType = strtolower((string) ($type ?? $request->input('schedule_type', '')));
        if (! in_array($scheduleType, ['vaccination', 'medication'], true)) {
            return $this->sendValidationError('Validation failed', [
                'type' => ['Schedule type must be vaccination or medication'],
            ]);
        }

        $batchSchedule = BatchSchedule::where('farm_id', $farm->id)
            ->find($request->batch_schedule_id);

        if (! $batchSchedule) {
            return $this->sendValidationError('Validation failed', [
                'batch_schedule_id' => ['Batch schedule does not belong to this farm'],
            ]);
        }

        $flock = Flock::find($batchSchedule->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $rules = [
            'batch_schedule_id' => 'required|exists:batch_schedules,id',
            'schedule_item_id' => 'required|exists:schedule_items,id',
            'status' => 'sometimes|in:scheduled,completed,missed,late',
            'scheduled_date' => 'required|date',
            'actual_date' => 'nullable|date',
            'administered_by' => 'nullable|string|max:255',
            'dosage' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'administration_method_id' => 'nullable|exists:administration_methods,id',
            'poultry_vaccine_product_id' => 'nullable|exists:poultry_vaccine_products,id',
            'vaccine_product_batch_id' => 'nullable|integer',
            'poultry_medication_id' => 'nullable|exists:poultry_medications,id',
        ];

        if ($scheduleType === 'vaccination') {
            $rules['poultry_vaccine_product_id'] = 'required|exists:poultry_vaccine_products,id';
        } else {
            $rules['poultry_medication_id'] = 'required|exists:poultry_medications,id';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $payload = [
            'batch_schedule_id' => $request->batch_schedule_id,
            'schedule_item_id' => $request->schedule_item_id,
            'status' => $request->input('status', 'completed'),
            'scheduled_date' => $request->scheduled_date,
            'actual_date' => $request->actual_date,
            'administered_by' => $request->administered_by,
            'dosage' => $request->dosage,
            'quantity' => $request->quantity,
            'cost' => $request->cost,
            'notes' => $request->notes,
            'administration_method_id' => $request->administration_method_id,
            'poultry_vaccine_product_id' => $scheduleType === 'vaccination' ? $request->poultry_vaccine_product_id : null,
            'vaccine_product_batch_id' => $scheduleType === 'vaccination' ? $request->vaccine_product_batch_id : null,
            'poultry_medication_id' => $scheduleType === 'medication' ? $request->poultry_medication_id : null,
        ];

        $item = DB::transaction(function () use ($payload, $scheduleType, $request) {
            $item = BatchScheduleItem::create($payload);
            $item->load(['batchSchedule', 'scheduleItem']);
            FlockExpenditure::recordFromBatchScheduleItem($item, $scheduleType, $request->user()->id);

            return $item;
        });

        return $this->sendResponse(
            $item,
            'Batch schedule item created successfully',
            201
        );
    }

    public function show($farmId, $type, $id)
    {
        $farm = Farm::findOrFail($farmId);
        if ($response = $this->authorizeFarm($farm, 'view flocks')) {
            return $response;
        }

        $item = BatchScheduleItem::with(['batchSchedule', 'scheduleItem'])->findOrFail($id);

        return $this->sendResponse($item, 'Batch schedule item retrieved successfully');
    }

    public function update(Request $request, $farmId, $type, $id)
    {
        $farm = Farm::findOrFail($farmId);
        if ($response = $this->authorizeFarm($farm, 'update flocks')) {
            return $response;
        }

        $item = BatchScheduleItem::findOrFail($id);

        $flock = Flock::find($item->batchSchedule?->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'batch_schedule_id' => 'sometimes|required|exists:batch_schedules,id',
            'schedule_item_id' => 'sometimes|required|exists:schedule_items,id',
            'status' => 'sometimes|in:scheduled,completed,missed,late',
            'scheduled_date' => 'sometimes|required|date',
            'actual_date' => 'nullable|date',
            'administered_by' => 'nullable|string|max:255',
            'poultry_vaccine_product_id' => 'nullable|exists:poultry_vaccine_products,id',
            'vaccine_product_batch_id' => 'nullable|integer',
            'poultry_medication_id' => 'nullable|exists:poultry_medications,id',
            'dosage' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'administration_method_id' => 'nullable|exists:administration_methods,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $scheduleType = strtolower((string) ($type ?? ''));

        $item = DB::transaction(function () use ($request, $item, $scheduleType) {
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

            $item = $item->fresh(['batchSchedule', 'scheduleItem']);
            if (in_array($scheduleType, ['vaccination', 'medication'], true)) {
                FlockExpenditure::recordFromBatchScheduleItem($item, $scheduleType, $request->user()->id);
            }

            return $item;
        });

        return $this->sendResponse($item, 'Batch schedule item updated successfully');
    }

    public function destroy($farmId, $type, $id)
    {
        $farm = Farm::findOrFail($farmId);
        if ($response = $this->authorizeFarm($farm, 'update flocks')) {
            return $response;
        }

        $item = BatchScheduleItem::findOrFail($id);

        $flock = Flock::find($item->batchSchedule?->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        DB::transaction(function () use ($item) {
            FlockExpenditure::deleteForSource('batch_schedule_item', $item->id);
            $item->delete();
        });

        return $this->sendResponse(null, 'Batch schedule item deleted successfully');
    }
}
