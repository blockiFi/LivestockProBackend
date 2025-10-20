<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\PoultryFeedInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FeedInventoryController extends ApiController
{
    public function index(Request $request, $farm ,$pagination = null)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed inventories', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed inventories');
        }
        $query = PoultryFeedInventory::where('farm_id', $farm->id);
        if ($request->has('feed_type_id')) {
            $query->where('poultry_feed_type_id', $request->feed_type_id);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('batch_number', 'like', "%{$search}%");
        }

        $sortField = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        if ($pagination) {
            $perPage = $request->input('per_page', 10);
            $inventories = $query->paginate($perPage);
        } else {
            $inventories = $query->get();
        }
        return $this->sendResponse($inventories, 'Feed inventories retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create feed inventories', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create feed inventories');
        }
        $validator = Validator::make($request->all(), [
            'poultry_feed_type_id' => 'required|exists:poultry_feed_types,id',
            'quantity' => 'required|numeric|min:0',
            'batch_number' => 'nullable|string|max:255',
            'manufacturer' => 'nullable|string|max:255',
            'manufacture_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:manufacture_date',
            'unit_cost' => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $inventory = PoultryFeedInventory::create(array_merge($request->all(), [
            'farm_id' => $farm->id,
            "created_by" => $user->id
        ]));
        return $this->sendResponse($inventory->load('createdby'), 'Feed inventory created successfully', 201);
    }

    public function show(Request $request, $farm, PoultryFeedInventory $inventory)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed inventories', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed inventories');
        }
        if ($inventory->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed inventory not found in this farm');
        }
        return $this->sendResponse($inventory, 'Feed inventory retrieved successfully');
    }

    public function update(Request $request, $farm, PoultryFeedInventory $inventory)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update feed inventories', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update feed inventories');
        }
        if ($inventory->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed inventory not found in this farm');
        }
        $validator = Validator::make($request->all(), [
            'poultry_feed_type_id' => 'sometimes|required|exists:poultry_feed_types,id',
            'quantity' => 'sometimes|numeric|min:0',
            'batch_number' => 'nullable|string|max:255',
            'manufacturer' => 'nullable|string|max:255',
            'manufacture_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:manufacture_date',
            'unit_cost' => 'sometimes|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $inventory->update($request->all());
        return $this->sendResponse($inventory, 'Feed inventory updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryFeedInventory $inventory)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete feed inventories', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete feed inventories');
        }
        if ($inventory->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed inventory not found in this farm');
        }
        if($inventory->status !== 'available'){
            return $this->sendError("cant delete an inventory in-use or depleted" );
        }
        $inventory->delete();
        return $this->sendResponse(null, 'Feed inventory deleted successfully');
    }

    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed inventories', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed inventories');
        }
        $query = PoultryFeedInventory::where('farm_id', $farm->id);
        $statistics = [
            'total_feed_inventories' => $query->count(),
            'total_quantity' => $query->sum('quantity'),
            'by_feed_type' => $query->selectRaw('poultry_feed_type_id, sum(quantity) as total_quantity')->groupBy('poultry_feed_type_id')->get(),
        ];
        return $this->sendResponse($statistics, 'Feed inventory statistics retrieved successfully');
    }
} 