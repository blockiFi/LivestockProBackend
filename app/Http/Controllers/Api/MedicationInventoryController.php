<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\PoultryMedicationInventory;
use App\Models\MedicationProduct;
use App\Models\Country;
use App\Support\MedicationQuantity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class MedicationInventoryController extends ApiController
{
    /**
     * Display a listing of medication inventory for a specific farm.
     */
    public function index(Request $request, $farmId, $paginated = null)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('view medication inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view medication inventory', [], 403);
        }
        $query = PoultryMedicationInventory::with(['product', 'createdBy'])
            ->where('farm_id', $farmId);
        if ($request->has('status')) {
            $query->where('status', $request->status);
        } elseif (! $request->boolean('include_depleted')) {
            $query->where('status', '!=', 'depleted');
        }
        if ($request->has('product_id')) {
            $query->where('medication_product_id', $request->product_id);
        }
        if ($request->has('batch_number')) {
            $query->where('batch_number', 'like', '%' . $request->batch_number . '%');
        }
        if ($request->has('expiry_status')) {
            switch ($request->expiry_status) {
                case 'expired':
                    $query->where('expiry_date', '<', now());
                    break;
                case 'expiring_soon':
                    $query->where('expiry_date', '<=', now()->addDays(30))->where('expiry_date', '>', now());
                    break;
                case 'valid':
                    $query->where('expiry_date', '>', now());
                    break;
            }
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($outer) use ($search) {
                $outer->whereHas('product', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('manufacturer', 'like', "%{$search}%");
                })->orWhere('batch_number', 'like', "%{$search}%");
            });
        }
        $sortField = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);
        
        if ($paginated || $request->has('page') || $request->has('per_page')) {
            $inventory = $query->paginate($request->per_page ?? 15);
        } else {
            $inventory = $query->get();
        }
        
        return $this->sendResponse($inventory, 'Medication inventory retrieved successfully');
    }

    /**
     * Store a newly created medication inventory item.
     */
    public function store(Request $request, $farmId)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('manage medication inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage medication inventory', [], 403);
        }
        $validator = Validator::make($request->all(), [
            'medication_product_id' => 'required|exists:medication_products,id',
            'packs_received' => 'required_without:quantity|numeric|gt:0|max:1000000',
            'quantity' => 'nullable|numeric|gt:0|max:1000000',
            'batch_number' => 'nullable|string|max:255',
            'manufacture_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:manufacture_date',
            'unit_cost' => 'required|numeric|min:0',
            'manufacturer' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        if ($request->filled('expiry_date') && \Carbon\Carbon::parse($request->expiry_date)->endOfDay()->isPast()) {
            return $this->sendValidationError('Validation failed', [
                'expiry_date' => ['The Medication is already expired, can\'t add stock.']
            ]);
        }
        $product = MedicationProduct::findOrFail($request->medication_product_id);
        if ($product->farm_id !== null && (int) $product->farm_id !== (int) $farm->id) {
            return $this->sendError('Medication product does not belong to this farm', [], 403);
        }
        if (! $product->hasPackSize()) {
            return $this->sendValidationError('Validation failed', [
                'medication_product_id' => ['Set the pack size on this product before adding stock.'],
            ]);
        }
        // Older clients send `quantity` as the number of packs.
        $packs = round((float) ($request->input('packs_received') ?? $request->input('quantity')), 2);
        $packSizeBase = (float) $product->packSizeBase();
        $totalContent = round($packs * $packSizeBase, 4);
        try {
            DB::beginTransaction();
            $inventory = PoultryMedicationInventory::create([
                'medication_product_id' => $request->medication_product_id,
                'farm_id' => $farm->id,
                'packs_received' => $packs,
                'pack_size_base' => $packSizeBase,
                'content_unit' => MedicationQuantity::baseUnit($product->pack_size_unit),
                'total_content' => $totalContent,
                'quantity' => $totalContent,
                'available_quantity' => $totalContent,
                'batch_number' => $request->batch_number,
                'manufacture_date' => $request->manufacture_date,
                'expiry_date' => $request->expiry_date,
                'last_restocked' => now()->toDateString(),
                'unit_cost' => $request->unit_cost,
                'manufacturer' => $request->filled('manufacturer') ? $request->manufacturer : $product->manufacturer,
                'notes' => $request->notes,
                'status' => $totalContent > 0 ? 'available' : 'depleted',
                'created_by' => auth()->id(),
            ]);
            DB::commit();
            $inventory->load(['product', 'createdBy']);
            return $this->sendResponse($inventory, 'Medication inventory created successfully', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to create medication inventory', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified medication inventory item.
     */
    public function show($farmId, $id)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('view medication inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view medication inventory', [], 403);
        }
        $inventory = PoultryMedicationInventory::with(['product', 'createdBy'])
            ->where('farm_id', $farmId)
            ->findOrFail($id);
        return $this->sendResponse($inventory, 'Medication inventory retrieved successfully');
    }

    /**
     * Update the specified medication inventory item.
     */
    public function update(Request $request, $farmId, $id)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('manage medication inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage medication inventory', [], 403);
        }
        $inventory = PoultryMedicationInventory::where('farm_id', $farmId)->findOrFail($id);
        $validator = Validator::make($request->all(), [
            'quantity' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:available,in_use,depleted',
            'batch_number' => 'nullable|string|max:255',
            'manufacture_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:manufacture_date',
            'unit_cost' => 'sometimes|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        try {
            DB::beginTransaction();
            $inventory->update($request->only([
                'quantity', 'status', 'batch_number', 'manufacture_date', 
                'expiry_date', 'unit_cost'
            ]));
            DB::commit();
            $inventory->load(['product', 'createdBy']);
            return $this->sendResponse($inventory, 'Medication inventory updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to update medication inventory', [], 500);
        }
    }

    /**
     * Remove the specified medication inventory item.
     */
    public function destroy($farmId, $id)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('manage medication inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage medication inventory', [], 403);
        }
        $inventory = PoultryMedicationInventory::where('farm_id', $farmId)->findOrFail($id);
        try {
            DB::beginTransaction();
            $inventory->delete();
            DB::commit();
            return $this->sendResponse(null, 'Medication inventory deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to delete medication inventory', [], 500);
        }
    }

    /**
     * Get medication inventory statistics for a farm.
     */
    public function statistics($farmId)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('view medication inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view medication inventory', [], 403);
        }
        $query = PoultryMedicationInventory::where('farm_id', $farmId);
        $statistics = [
            'total_items' => $query->count(),
            'total_quantity' => $query->sum('quantity'),
            'total_value' => $query->sum(DB::raw('CASE WHEN pack_size_base > 0 THEN quantity / pack_size_base * unit_cost ELSE quantity * unit_cost END')),
            'by_status' => $query->selectRaw('status, count(*) as count, sum(quantity) as total_quantity')
                                ->groupBy('status')
                                ->get(),
            'expired_items' => $query->where('expiry_date', '<', now())->count(),
            'expiring_soon' => $query->where('expiry_date', '<=', now()->addDays(30))->where('expiry_date', '>', now())->count(),
            'low_stock' => $query->where('quantity', '<', 10)->count(),
            'by_product' => $query->selectRaw('medication_product_id, sum(quantity) as total_quantity, sum(CASE WHEN pack_size_base > 0 THEN quantity / pack_size_base * unit_cost ELSE quantity * unit_cost END) as total_value')
                                ->groupBy('medication_product_id')
                                ->with('product:id,name')
                                ->get(),
        ];
        return $this->sendResponse($statistics, 'Medication inventory statistics retrieved successfully');
    }

    /**
     * Get medication inventory alerts (expired, expiring soon, low stock).
     */
    public function alerts($farmId)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('view medication inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view medication inventory', [], 403);
        }
        $query = PoultryMedicationInventory::with(['product'])
            ->where('farm_id', $farmId);
        $alerts = [
            'expired' => $query->where('expiry_date', '<', now())->get(),
            'expiring_soon' => $query->where('expiry_date', '<=', now()->addDays(30))->where('expiry_date', '>', now())->get(),
            'low_stock' => $query->where('quantity', '<', 10)->get(),
            'depleted' => $query->where('status', 'depleted')->get(),
        ];
        return $this->sendResponse($alerts, 'Medication inventory alerts retrieved successfully');
    }

    /**
     * Get available medication products for inventory creation.
     */
    public function availableProducts($farmId)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('view medication inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view medication inventory', [], 403);
        }
        $products = MedicationProduct::where(function($query) use ($farm) {
                $query->where('farm_id', $farm->id)
                      ->orWhereNull('farm_id');
            })
            ->get();
        return $this->sendResponse($products, 'Available medication products retrieved successfully');
    }

    /**
     * Write off whatever is left in a batch (wasted, expired, ...). Logs the amount and value only;
     * no expense entry is created.
     */
    public function deplete(Request $request, $farmId, $id)
    {
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('manage medication inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage medication inventory', [], 403);
        }
        $validator = Validator::make($request->all(), [
            'reason' => ['required', Rule::in(MedicationQuantity::DEPLETION_REASONS)],
            'note' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $inventory = DB::transaction(function () use ($farm, $id, $request) {
            $inventory = PoultryMedicationInventory::where('farm_id', $farm->id)
                ->lockForUpdate()
                ->find($id);
            if (! $inventory) {
                return null;
            }
            if ($inventory->status === 'depleted' && (float) $inventory->quantity <= 0) {
                return false;
            }

            $remaining = max(0, (float) $inventory->quantity);
            $inventory->fill([
                'written_off_quantity' => $remaining,
                'written_off_value' => $inventory->costOf($remaining),
                'quantity' => 0,
                'status' => 'depleted',
                'depleted_at' => now(),
                'depleted_by' => auth()->id(),
                'depletion_reason' => $request->reason,
                'depletion_note' => $request->note,
            ])->save();

            return $inventory;
        });

        if ($inventory === null) {
            return $this->sendNotFoundError('Medication inventory not found in this farm');
        }
        if ($inventory === false) {
            return $this->sendValidationError('Validation failed', [
                'inventory' => ['This batch is already depleted.'],
            ]);
        }

        $inventory->load(['product', 'createdBy', 'depletedBy']);

        return $this->sendResponse($inventory, 'Batch marked as depleted');
    }

    /**
     * Bulk update medication inventory status.
     */
    public function bulkUpdateStatus(Request $request, $farmId)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        if (!auth()->user()->can('manage medication inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage medication inventory', [], 403);
        }
        $validator = Validator::make($request->all(), [
            'inventory_ids' => 'required|array',
            'inventory_ids.*' => 'exists:poultry_medication_inventories,id',
            'status' => 'required|in:available,in_use,depleted',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        try {
            DB::beginTransaction();
            $updatedCount = PoultryMedicationInventory::where('farm_id', $farm->id)
                ->whereIn('id', $request->inventory_ids)
                ->update(['status' => $request->status]);
            DB::commit();
            return $this->sendResponse(['updated_count' => $updatedCount], 'Medication inventory status updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to update medication inventory status', [], 500);
        }
    }
} 