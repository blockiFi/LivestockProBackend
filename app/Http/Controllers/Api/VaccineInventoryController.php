<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\PoultryVaccineInventory;
use App\Models\PoultryVaccineProduct;
use App\Models\Country;
use App\Traits\RegisterEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class VaccineInventoryController extends ApiController
{
    use RegisterEvents;

    /**
     * Display a listing of vaccine inventory for a specific farm.
     */
    public function index(Request $request, $farmId , $paginated = null)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }

        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view vaccine inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view vaccine inventory', [], 403);
        }

        $query = PoultryVaccineInventory::with(['product.vaccine', 'createdBy'])
            ->where('farm_id', $farmId);

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('product_id')) {
            $query->where('poultry_vaccine_product_id', $request->product_id);
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
                    $query->expiringSoon();
                    break;
                case 'valid':
                    $query->notExpired();
                    break;
            }
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('product', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('manufacturer', 'like', "%{$search}%");
            })->orWhere('batch_number', 'like', "%{$search}%");
        }

        // Apply sorting
        $sortField = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        if ($paginated || $request->has('page') || $request->has('per_page')) {
            $perPage = $request->input('per_page', 15);
            $inventory = $query->paginate($perPage);
        } else {
            $inventory = $query->get();
        }

        return $this->sendResponse($inventory, 'Vaccine inventory retrieved successfully');
    }

    /**
     * Store a newly created vaccine inventory item.
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

        if (!auth()->user()->can('manage vaccine inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage vaccine inventory', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'poultry_vaccine_product_id' => 'required|exists:poultry_vaccine_products,id',
            'quantity' => 'required|numeric|min:0',
            'batch_number' => 'nullable|string|max:255',
            'manufacture_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:manufacture_date',
            'unit_cost' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        // Validate that the vaccine has not expired
        if ($request->filled('expiry_date') && \Carbon\Carbon::parse($request->expiry_date)->isPast()) {
            return $this->sendValidationError('Validation failed', [
                'expiry_date' => ['The Vaccine is already Exipired, Cant add Stock.']
            ]);
        }
        // Verify product belongs to farm or is default
        $product = PoultryVaccineProduct::findOrFail($request->poultry_vaccine_product_id);
        if ($product->farm_id !== null && $product->farm_id !== $farm->id) {
            return $this->sendError('Vaccine product does not belong to this farm', [], 403);
        }

        try {
            DB::beginTransaction();
           
            $status = 'available';
            $inventory = PoultryVaccineInventory::create([
                'poultry_vaccine_product_id' => $request->poultry_vaccine_product_id,
                'farm_id' => $farm->id,
                'quantity' => $request->quantity,
                'available_quantity' => $request->quantity,
                'batch_number' => $request->batch_number,
                'status' => "available",
                'manufacture_date' => $request->manufacture_date,
                'expiry_date' => $request->expiry_date,
                'unit_cost' => $request->unit_cost,
                'created_by' => auth()->id(),

            ]);

            $this->RegisterEvent(
                farmId: $farm->id,
                eventType: 'vaccine_inventory_created',
                tableName: 'poultry_vaccine_inventories',
                tableId: $inventory->id
            );

            DB::commit();

            $inventory->load(['product.vaccine', 'createdBy']);

            return $this->sendResponse($inventory, 'Vaccine inventory created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to create vaccine inventory', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified vaccine inventory item.
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

        if (!auth()->user()->can('view vaccine inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view vaccine inventory', [], 403);
        }

        $inventory = PoultryVaccineInventory::with(['product.vaccine', 'createdBy', 'vaccinationRecords'])
            ->where('farm_id', $farmId)
            ->findOrFail($id);

        return $this->sendResponse($inventory, 'Vaccine inventory retrieved successfully');
    }

    /**
     * Update the specified vaccine inventory item.
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

        if (!auth()->user()->can('manage vaccine inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage vaccine inventory', [], 403);
        }

        $inventory = PoultryVaccineInventory::where('farm_id', $farmId)->findOrFail($id);

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

            $this->RegisterEvent(
                farmId: $farm->id,
                eventType: 'vaccine_inventory_updated',
                tableName: 'poultry_vaccine_inventories',
                tableId: $inventory->id
            );

            DB::commit();

            $inventory->load(['product.vaccine', 'createdBy']);

            return $this->sendResponse($inventory, 'Vaccine inventory updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to update vaccine inventory', [], 500);
        }
    }

    /**
     * Remove the specified vaccine inventory item.
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

        if (!auth()->user()->can('manage vaccine inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage vaccine inventory', [], 403);
        }

        $inventory = PoultryVaccineInventory::where('farm_id', $farmId)->findOrFail($id);

        try {
            DB::beginTransaction();

            $this->RegisterEvent(
                farmId: $farm->id,
                eventType: 'vaccine_inventory_deleted',
                tableName: 'poultry_vaccine_inventories',
                tableId: $inventory->id
            );

            $inventory->delete();

            DB::commit();

            return $this->sendResponse(null, 'Vaccine inventory deleted successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to delete vaccine inventory', [], 500);
        }
    }

    /**
     * Get vaccine inventory statistics for a farm.
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

        if (!auth()->user()->can('view vaccine inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view vaccine inventory', [], 403);
        }

        $query = PoultryVaccineInventory::where('farm_id', $farmId);

        $statistics = [
            'total_items' => $query->count(),
            'total_quantity' => $query->sum('quantity'),
            'total_value' => $query->sum(DB::raw('quantity * unit_cost')),
            'by_status' => $query->selectRaw('status, count(*) as count, sum(quantity) as total_quantity')
                                ->groupBy('status')
                                ->get(),
            'expired_items' => $query->where('expiry_date', '<', now())->count(),
            'expiring_soon' => $query->expiringSoon()->count(),
            'low_stock' => $query->where('quantity', '<', 10)->count(),
            'by_product' => $query->selectRaw('poultry_vaccine_product_id, sum(quantity) as total_quantity, sum(quantity * unit_cost) as total_value')
                                ->groupBy('poultry_vaccine_product_id')
                                ->with('product:id,name')
                                ->get(),
        ];

        return $this->sendResponse($statistics, 'Vaccine inventory statistics retrieved successfully');
    }

    /**
     * Get vaccine inventory alerts (expired, expiring soon, low stock).
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

        if (!auth()->user()->can('view vaccine inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view vaccine inventory', [], 403);
        }

        $query = PoultryVaccineInventory::with(['product.vaccine'])
            ->where('farm_id', $farmId);

        $alerts = [
            'expired' => $query->where('expiry_date', '<', now())->get(),
            'expiring_soon' => $query->expiringSoon()->get(),
            'low_stock' => $query->where('quantity', '<', 10)->get(),
            'depleted' => $query->depleted()->get(),
        ];

        return $this->sendResponse($alerts, 'Vaccine inventory alerts retrieved successfully');
    }

    /**
     * Get available vaccine products for inventory creation.
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

        if (!auth()->user()->can('view vaccine inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view vaccine inventory', [], 403);
        }

        $products = PoultryVaccineProduct::with(['vaccine', 'administrationMethod'])
            ->where(function($query) use ($farm) {
                $query->where('farm_id', $farm->id)
                      ->orWhereNull('farm_id'); // Include default products
            })
            ->get();

        return $this->sendResponse($products, 'Available vaccine products retrieved successfully');
    }

    /**
     * Get countries for inventory creation.
     */
    public function countries()
    {
        // Some installations don't have a `code` column on `countries`
        $countries = Country::select('id', 'name')->get();
        return $this->sendResponse($countries, 'Countries retrieved successfully');
    }

    /**
     * Bulk update vaccine inventory status.
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

        if (!auth()->user()->can('manage vaccine inventory', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage vaccine inventory', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'inventory_ids' => 'required|array',
            'inventory_ids.*' => 'exists:poultry_vaccine_inventories,id',
            'status' => 'required|in:available,in_use,depleted',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        try {
            DB::beginTransaction();

            $updatedCount = PoultryVaccineInventory::where('farm_id', $farmId)
                ->whereIn('id', $request->inventory_ids)
                ->update(['status' => $request->status]);

            $this->RegisterEvent(
                farmId: $farm->id,
                eventType: 'vaccine_inventory_bulk_status_update',
                tableName: 'poultry_vaccine_inventories',
                tableId: null,
                additionalData: [
                    'updated_count' => $updatedCount,
                    'new_status' => $request->status
                ]
            );

            DB::commit();

            return $this->sendResponse(['updated_count' => $updatedCount], 'Vaccine inventory status updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to update vaccine inventory status', [], 500);
        }
    }
}
