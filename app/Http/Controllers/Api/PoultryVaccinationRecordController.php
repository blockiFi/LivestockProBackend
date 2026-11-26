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
     * Cost typed in on the form, or null when left empty.
     */
    private function enteredCost(Request $request): ?float
    {
        return $request->filled('cost') ? round((float) $request->input('cost'), 2) : null;
    }

    private function restock(PoultryVaccineInventory $inventory, float $delta): void
    {
        $inventory->quantity = (float) $inventory->quantity + $delta;
        $inventory->status = $inventory->quantity <= 0 ? 'depleted' : 'available';
        $inventory->save();
    }

    /**
     * Store a newly created vaccination record.
     */
    public function store(Request $request, $farmId)
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|exists:farms,id',
            'flock_id' => 'required|exists:flocks,id',
            'poultry_vaccine_id' => 'required|exists:poultry_vaccines,id',
            'poultry_vaccine_inventory_id' => 'nullable|exists:poultry_vaccine_inventories,id',
            'date' => 'required|date',
            'administered_by' => 'required|string|max:255',
            'dosage' => 'required|numeric|min:0',
            'dosage_unit' => 'required|string|max:50',
            // Only needed to deduct stock; optional when nothing is drawn from inventory.
            'quantity' => 'required_with:poultry_vaccine_inventory_id|nullable|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
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

            $enteredCost = $this->enteredCost($request);
            $quantityUsed = $request->filled('quantity') ? (float) $request->quantity : 0.0;

            if (! $request->filled('poultry_vaccine_inventory_id')) {
                // Not drawn from stock: nothing is deducted, cost is whatever was entered.
                $vaccinationRecord = PoultryVaccinationRecord::create([
                    'farm_id' => $request->farm_id,
                    'flock_id' => $request->flock_id,
                    'poultry_vaccine_id' => $request->poultry_vaccine_id,
                    'poultry_vaccine_inventory_id' => null,
                    'date' => $request->date,
                    'administered_by' => $request->administered_by,
                    'dosage' => $request->dosage,
                    'dosage_unit' => $request->dosage_unit,
                    'quantity' => $quantityUsed,
                    'cost' => $enteredCost,
                    'notes' => $request->notes,
                    'administration_method_id' => $request->administration_method_id,
                ]);

                FlockExpenditure::recordFromVaccination($vaccinationRecord);

                $vaccinationRecord->load(['vaccine', 'vaccineInventory', 'administrationMethod']);

                DB::commit();

                return $this->sendResponse($vaccinationRecord, 'Vaccination record created successfully');
            }

            $inventory = PoultryVaccineInventory::where('farm_id', $farm->id)
                ->lockForUpdate()
                ->find($request->poultry_vaccine_inventory_id);

            if (! $inventory) {
                DB::rollback();
                return $this->sendValidationError('Validation failed', [
                    'poultry_vaccine_inventory_id' => ['This inventory batch does not belong to this farm.'],
                ]);
            }

            // Check if there's enough quantity in inventory
            if ($inventory->quantity < $request->quantity) {
                DB::rollback();
                return $this->sendError('Insufficient vaccine quantity in inventory', [
                    'available_quantity' => $inventory->quantity,
                    'requested_quantity' => $request->quantity
                ], 400);
            }
            
            // An entered cost wins; otherwise price the doses used from the batch.
            $calculatedCost = $enteredCost ?? round($request->quantity * $inventory->unit_cost, 2);

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
            'poultry_vaccine_inventory_id' => 'sometimes|nullable|exists:poultry_vaccine_inventories,id',
            'date' => 'sometimes|required|date',
            'administered_by' => 'sometimes|required|string|max:255',
            'dosage' => 'sometimes|required|numeric|min:0',
            'dosage_unit' => 'sometimes|required|string|max:50',
            'quantity' => 'sometimes|nullable|numeric|min:0',
            'cost' => 'sometimes|nullable|numeric|min:0',
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

            $oldInventoryId = (int) $vaccinationRecord->poultry_vaccine_inventory_id;
            $oldQuantity = (float) $vaccinationRecord->quantity;
            $inventoryId = $request->exists('poultry_vaccine_inventory_id')
                ? (int) $request->input('poultry_vaccine_inventory_id')
                : $oldInventoryId;
            $quantity = $request->has('quantity') ? (float) $request->input('quantity') : $oldQuantity;
            $stockTouched = $request->has('poultry_vaccine_inventory_id') || $request->has('quantity');
            $inventory = null;

            if ($stockTouched) {
                // Give the old batch back what it lost, then take from the (new) batch.
                if ($oldInventoryId && ($oldInventory = PoultryVaccineInventory::lockForUpdate()->find($oldInventoryId))) {
                    $this->restock($oldInventory, $oldQuantity);
                }

                $updateData['poultry_vaccine_inventory_id'] = $inventoryId ?: null;
                $updateData['quantity'] = $quantity;

                if ($inventoryId) {
                    $inventory = PoultryVaccineInventory::where('farm_id', $farm->id)
                        ->lockForUpdate()
                        ->find($inventoryId);
                    if (! $inventory) {
                        DB::rollback();
                        return $this->sendValidationError('Validation failed', [
                            'poultry_vaccine_inventory_id' => ['This inventory batch does not belong to this farm.'],
                        ]);
                    }
                    if ((float) $inventory->quantity + 1e-6 < $quantity) {
                        DB::rollback();
                        return $this->sendError('Insufficient vaccine quantity in inventory', [
                            'available_quantity' => $inventory->quantity,
                            'requested_quantity' => $quantity,
                        ], 400);
                    }
                    $this->restock($inventory, -$quantity);
                }
            } elseif ($inventoryId) {
                $inventory = PoultryVaccineInventory::find($inventoryId);
            }

            $inventoryCost = $inventory ? round($quantity * (float) $inventory->unit_cost, 2) : null;

            if ($request->exists('cost')) {
                // Entered cost wins; clearing it falls back to the batch price (if any).
                $updateData['cost'] = $this->enteredCost($request) ?? $inventoryCost;
            } elseif ($stockTouched && ($inventoryId || $oldInventoryId)) {
                $updateData['cost'] = $inventoryCost;
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

            // Give the used doses back to the batch they came from (records off stock have none).
            $inventory = $vaccinationRecord->poultry_vaccine_inventory_id
                ? PoultryVaccineInventory::lockForUpdate()->find($vaccinationRecord->poultry_vaccine_inventory_id)
                : null;

            if ($inventory) {
                $this->restock($inventory, (float) $vaccinationRecord->quantity);
            }

            // Delete the vaccination record
            $vaccinationRecord->delete();

            // Remove linked expenditure (if any)
            FlockExpenditure::deleteForSource('vaccination_record', $vaccinationRecord->id);

            DB::commit();

            return $this->sendResponse([], $inventory
                ? 'Vaccination record deleted successfully and inventory quantity restored'
                : 'Vaccination record deleted successfully');
        } catch (\Exception $e) {
            DB::rollback();
            return $this->sendError('Failed to delete vaccination record', [$e->getMessage()], 500);
        }
    }
}
