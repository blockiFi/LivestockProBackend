<?php

namespace App\Http\Controllers\Api;

use App\Models\PoultryVaccinationRecord;
use App\Models\PoultryVaccineInventory;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockExpenditure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PoultryVaccinationRecordController extends ApiController
{
    /**
     * Store a newly created vaccination record.
     */
    public function store(Request $request, $farmId)
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|exists:farms,id',
            'flock_id' => 'required|exists:flocks,id',
            'poultry_vaccine_id' => 'required|exists:poultry_vaccines,id',
            'poultry_vaccine_inventory_id' => 'required|exists:poultry_vaccine_inventories,id',
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

        // Check if user has permission to create vaccination records for this farm
        if (!auth()->user()->can('create vaccination records', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to create vaccination records for this farm', [], 403);
        }

        [$flock, $inactiveResponse] = $this->activeFlockForFarm((int) $request->flock_id, $farm->id);
        if ($inactiveResponse) {
            return $inactiveResponse;
        }

        try {
            DB::beginTransaction();

            // Get the vaccine inventory to calculate cost
            $inventory = PoultryVaccineInventory::findOrFail($request->poultry_vaccine_inventory_id);
            
            // Check if there's enough quantity in inventory
            if ($inventory->quantity < $request->quantity) {
                DB::rollback();
                return $this->sendError('Insufficient vaccine quantity in inventory', [
                    'available_quantity' => $inventory->quantity,
                    'requested_quantity' => $request->quantity
                ], 400);
            }
            
            // Calculate cost based on quantity used and unit cost from inventory
            $calculatedCost = $request->quantity * $inventory->unit_cost;

            $vaccinationRecord = PoultryVaccinationRecord::create([
                'farm_id' => $request->farm_id,
                'flock_id' => $request->flock_id,
                'poultry_vaccine_id' => $request->poultry_vaccine_id,
                'poultry_vaccine_inventory_id' => $request->poultry_vaccine_inventory_id,
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
            $originalQuantity = $inventory->quantity;
            $inventory->quantity -= $request->quantity;
            
            // Log the inventory update for debugging
            \Log::info('Vaccination Record Created - Inventory Updated', [
                'inventory_id' => $inventory->id,
                'original_quantity' => $originalQuantity,
                'used_quantity' => $request->quantity,
                'new_quantity' => $inventory->quantity,
                'vaccination_record_id' => $vaccinationRecord->id
            ]);
            
            // Update status based on remaining quantity
            if ($inventory->quantity <= 0) {
                $inventory->status = 'depleted';
            } else {
                $inventory->status = 'available';
            }
            
            $inventory->save();

            // Auto-create flock expenditure record (if cost > 0)
            FlockExpenditure::recordFromVaccination($vaccinationRecord);

            // Load relationships
            $vaccinationRecord->load([
                'vaccine',
                'vaccineInventory',
                'administrationMethod'
            ]);

            DB::commit();

            return $this->sendResponse($vaccinationRecord, 'Vaccination record created successfully');

        } catch (\Exception $e) {
            DB::rollback();
            return $this->sendError('Failed to create vaccination record', [$e->getMessage()], 500);
        }
    }

    /**
     * Display a listing of vaccination records for a specific farm.
     */
    public function index(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (!auth()->user()->can('view vaccination records', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view vaccination records for this farm', [], 403);
        }

        $query = PoultryVaccinationRecord::with([
            'vaccine',
            'vaccineInventory',
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

        $vaccinationRecords = $query->orderBy('date', 'desc')->get();

        return $this->sendResponse($vaccinationRecords, 'Vaccination records retrieved successfully');
    }

    /**
     * Display the specified vaccination record.
     */
    public function show($farmId, $id)
    {
        $farm = Farm::findOrFail($farmId);

        if (!auth()->user()->can('view vaccination records', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view vaccination records for this farm', [], 403);
        }

        $vaccinationRecord = PoultryVaccinationRecord::with([
            'vaccine',
            'vaccineInventory',
            'administrationMethod',
            'flock'
        ])->where('farm_id', $farmId)->findOrFail($id);

        return $this->sendResponse($vaccinationRecord, 'Vaccination record retrieved successfully');
    }

    /**
     * Update the specified vaccination record.
     */
    public function update(Request $request, $farmId, $id)
    {
        $validator = Validator::make($request->all(), [
            'poultry_vaccine_id' => 'sometimes|required|exists:poultry_vaccines,id',
            'poultry_vaccine_inventory_id' => 'sometimes|required|exists:poultry_vaccine_inventories,id',
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

        if (!auth()->user()->can('update vaccination records', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to update vaccination records for this farm', [], 403);
        }

        $vaccinationRecord = PoultryVaccinationRecord::where('farm_id', $farmId)->findOrFail($id);

        $flock = Flock::find($vaccinationRecord->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        try {
            DB::beginTransaction();

            // Prepare data for update
            $updateData = $request->only([
                'poultry_vaccine_id',
                'poultry_vaccine_inventory_id',
                'date',
                'administered_by',
                'dosage',
                'dosage_unit',
                'quantity',
                'notes',
                'administration_method_id',
            ]);

            // If inventory or quantity is being updated, recalculate cost
            if ($request->has('poultry_vaccine_inventory_id') || $request->has('quantity')) {
                $inventoryId = $request->poultry_vaccine_inventory_id ?? $vaccinationRecord->poultry_vaccine_inventory_id;
                $quantity = $request->quantity ?? $vaccinationRecord->quantity;
                
                $inventory = PoultryVaccineInventory::findOrFail($inventoryId);
                $updateData['cost'] = $quantity * $inventory->unit_cost;
            }

            $vaccinationRecord->update($updateData);

            // Refresh expenditure record with latest cost (if any)
            FlockExpenditure::recordFromVaccination($vaccinationRecord);

            // Load relationships
            $vaccinationRecord->load([
                'vaccine',
                'vaccineInventory',
                'administrationMethod'
            ]);

            DB::commit();

            return $this->sendResponse($vaccinationRecord, 'Vaccination record updated successfully');

        } catch (\Exception $e) {
            DB::rollback();
            return $this->sendError('Failed to update vaccination record', [$e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified vaccination record.
     */
    public function destroy($farmId, $id)
    {
        $farm = Farm::findOrFail($farmId);

        if (!auth()->user()->can('delete vaccination records', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to delete vaccination records for this farm', [], 403);
        }

        $vaccinationRecord = PoultryVaccinationRecord::where('farm_id', $farmId)->findOrFail($id);

        $flock = Flock::find($vaccinationRecord->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        try {
            DB::beginTransaction();

            // Get the vaccine inventory to restore the quantity
            $inventory = PoultryVaccineInventory::findOrFail($vaccinationRecord->poultry_vaccine_inventory_id);
            
            // Restore the inventory quantity by adding back the used quantity
            $originalQuantity = $inventory->quantity;
            $inventory->quantity += $vaccinationRecord->quantity;
            
            // Log the inventory restoration for debugging
            \Log::info('Vaccination Record Deleted - Inventory Restored', [
                'inventory_id' => $inventory->id,
                'original_quantity' => $originalQuantity,
                'restored_quantity' => $vaccinationRecord->quantity,
                'new_quantity' => $inventory->quantity,
                'vaccination_record_id' => $vaccinationRecord->id
            ]);
            
            // Update status based on restored quantity
            if ($inventory->quantity <= 0) {
                $inventory->status = 'depleted';
            } else {
                $inventory->status = 'available';
            }
            
            $inventory->save();

            // Delete the vaccination record
            $vaccinationRecord->delete();

            // Remove linked expenditure (if any)
            FlockExpenditure::deleteForSource('vaccination_record', $vaccinationRecord->id);

            DB::commit();

            return $this->sendResponse([], 'Vaccination record deleted successfully and inventory quantity restored');
        } catch (\Exception $e) {
            DB::rollback();
            return $this->sendError('Failed to delete vaccination record', [$e->getMessage()], 500);
        }
    }
}
