<?php

namespace App\Http\Controllers\Api;

use App\Models\PoultryMedicationRecord;
use App\Models\PoultryMedicationInventory;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PoultryMedicationRecordController extends ApiController
{
    /**
     * Store a newly created medication record.
     */
    public function store(Request $request, $farmId)
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|exists:farms,id',
            'flock_id' => 'required|exists:flocks,id',
            'poultry_medication_id' => 'required|exists:poultry_medications,id',
            'poultry_medication_inventory_id' => 'required|exists:poultry_medication_inventories,id',
            'date' => 'required|date',
            'administered_by' => 'required|string|max:255',
            'dosage' => 'required|numeric|min:0',
            'dosage_unit' => 'required|string|max:50',
            'quantity' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'administration_method_id' => 'required|exists:administration_methods,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $farm = Farm::findOrFail($farmId);

        // Check if user has permission to create medication records for this farm
        if (!auth()->user()->can('create medication records', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to create medication records for this farm', [], 403);
        }

        try {
            DB::beginTransaction();

            // Get the medication inventory to calculate cost
            $inventory = PoultryMedicationInventory::findOrFail($request->poultry_medication_inventory_id);
            
            // Check if there's enough quantity in inventory
            if (!$inventory->hasSufficientQuantity($request->quantity)) {
                DB::rollback();
                return $this->sendError('Insufficient medication quantity in inventory', [
                    'available_quantity' => $inventory->quantity,
                    'requested_quantity' => $request->quantity
                ], 400);
            }
            
            // Calculate cost based on quantity used and unit cost from inventory
            $calculatedCost = $request->quantity * $inventory->unit_cost;

            $medicationRecord = PoultryMedicationRecord::create([
                'farm_id' => $request->farm_id,
                'flock_id' => $request->flock_id,
                'poultry_medication_id' => $request->poultry_medication_id,
                'poultry_medication_inventory_id' => $request->poultry_medication_inventory_id,
                'date' => $request->date,
                'administered_by' => $request->administered_by,
                'dosage' => $request->dosage,
                'dosage_unit' => $request->dosage_unit,
                'quantity' => $request->quantity,
                'cost' => $calculatedCost,
                'notes' => $request->notes,
                'administration_method_id' => $request->administration_method_id,
            ]);

            // Update inventory quantity by subtracting the used quantity
            $inventory->quantity -= $request->quantity;
            $inventory->updateStatus(); // This will save and update status automatically

            // Load relationships
            $medicationRecord->load([
                'medication',
                'medicationInventory',
                'administrationMethod'
            ]);

            DB::commit();

            return $this->sendResponse($medicationRecord, 'Medication record created successfully');

        } catch (\Exception $e) {
            DB::rollback();
            return $this->sendError('Failed to create medication record', [$e->getMessage()], 500);
        }
    }

    /**
     * Display a listing of medication records for a specific farm.
     */
    public function index(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (!auth()->user()->can('view medication records', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view medication records for this farm', [], 403);
        }

        $query = PoultryMedicationRecord::with([
            'medication',
            'medicationInventory',
            'administrationMethod',
            'flock'
        ])->where('farm_id', $farmId);

        // Filter by flock if specified
        if ($request->flock_id) {
            $query->where('flock_id', $request->flock_id);
        }

        // Filter by date range if specified
        if ($request->start_date) {
            $query->where('date', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $query->where('date', '<=', $request->end_date);
        }

        $medicationRecords = $query->orderBy('date', 'desc')->get();

        return $this->sendResponse($medicationRecords, 'Medication records retrieved successfully');
    }

    /**
     * Display the specified medication record.
     */
    public function show($farmId, $id)
    {
        $farm = Farm::findOrFail($farmId);

        if (!auth()->user()->can('view medication records', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view medication records for this farm', [], 403);
        }

        $medicationRecord = PoultryMedicationRecord::with([
            'medication',
            'medicationInventory',
            'administrationMethod',
            'flock'
        ])->where('farm_id', $farmId)->findOrFail($id);

        return $this->sendResponse($medicationRecord, 'Medication record retrieved successfully');
    }

    /**
     * Update the specified medication record.
     */
    public function update(Request $request, $farmId, $id)
    {
        $validator = Validator::make($request->all(), [
            'poultry_medication_id' => 'sometimes|required|exists:poultry_medications,id',
            'poultry_medication_inventory_id' => 'sometimes|required|exists:poultry_medication_inventories,id',
            'date' => 'sometimes|required|date',
            'administered_by' => 'sometimes|required|string|max:255',
            'dosage' => 'sometimes|required|numeric|min:0',
            'dosage_unit' => 'sometimes|required|string|max:50',
            'quantity' => 'sometimes|required|numeric|min:0',
            'notes' => 'nullable|string',
            'administration_method_id' => 'sometimes|required|exists:administration_methods,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $farm = Farm::findOrFail($farmId);

        if (!auth()->user()->can('update medication records', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to update medication records for this farm', [], 403);
        }

        $medicationRecord = PoultryMedicationRecord::where('farm_id', $farmId)->findOrFail($id);

        try {
            DB::beginTransaction();

            // Prepare data for update
            $updateData = $request->only([
                'poultry_medication_id',
                'poultry_medication_inventory_id',
                'date',
                'administered_by',
                'dosage',
                'dosage_unit',
                'quantity',
                'notes',
                'administration_method_id',
            ]);

            // If inventory or quantity is being updated, recalculate cost
            if ($request->has('poultry_medication_inventory_id') || $request->has('quantity')) {
                $inventoryId = $request->poultry_medication_inventory_id ?? $medicationRecord->poultry_medication_inventory_id;
                $quantity = $request->quantity ?? $medicationRecord->quantity;
                
                $inventory = PoultryMedicationInventory::findOrFail($inventoryId);
                $updateData['cost'] = $quantity * $inventory->unit_cost;
            }

            $medicationRecord->update($updateData);

            // Load relationships
            $medicationRecord->load([
                'medication',
                'medicationInventory',
                'administrationMethod'
            ]);

            DB::commit();

            return $this->sendResponse($medicationRecord, 'Medication record updated successfully');

        } catch (\Exception $e) {
            DB::rollback();
            return $this->sendError('Failed to update medication record', [$e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified medication record.
     */
    public function destroy($farmId, $id)
    {
        $farm = Farm::findOrFail($farmId);

        if (!auth()->user()->can('delete medication records', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to delete medication records for this farm', [], 403);
        }

        $medicationRecord = PoultryMedicationRecord::where('farm_id', $farmId)->findOrFail($id);

        try {
            DB::beginTransaction();

            // Get the medication inventory to restore the quantity
            $inventory = PoultryMedicationInventory::findOrFail($medicationRecord->poultry_medication_inventory_id);
            
            // Restore the inventory quantity by adding back the used quantity
            $inventory->quantity += $medicationRecord->quantity;
            $inventory->updateStatus(); // This will save and update status automatically

            // Delete the medication record
            $medicationRecord->delete();

            DB::commit();

            return $this->sendResponse([], 'Medication record deleted successfully and inventory quantity restored');
        } catch (\Exception $e) {
            DB::rollback();
            return $this->sendError('Failed to delete medication record', [$e->getMessage()], 500);
        }
    }
}
