<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\PoultryFeedUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FeedUsageController extends ApiController
{
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed usages');
        }
        $query = PoultryFeedUsage::where('farm_id', $farm->id);
        if ($request->has('feed_inventory_id')) {
            $query->where('poultry_feed_inventory_id', $request->feed_inventory_id);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('usage_date', 'like', "%{$search}%");
        }
        $sortField = $request->input('sort_by', 'usage_date');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);
        $perPage = $request->input('per_page', 10);
        $usages = $query->paginate($perPage);
        return $this->sendResponse($usages, 'Feed usages retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create feed usages');
        }
        $validator = Validator::make($request->all(), [
            'poultry_feed_inventory_id' => 'required|exists:poultry_feed_inventories,id',
            'poultry_feed_type_id' => 'required|exists:poultry_feed_types,id',
            'flock_id' => 'required|exists:flocks,id',
            'quantity' => 'required|numeric|min:0',
            'unit_cost' => 'required|numeric|min:0',
            'usage_date' => 'required|date',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $usage = PoultryFeedUsage::create(array_merge($request->all(), [
            'farm_id' => $farm->id
        ]));
        return $this->sendResponse($usage, 'Feed usage created successfully', 201);
    }

    public function show(Request $request, $farm, PoultryFeedUsage $usage)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed usages');
        }
        if ($usage->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed usage not found in this farm');
        }
        return $this->sendResponse($usage, 'Feed usage retrieved successfully');
    }

    public function update(Request $request, $farm, PoultryFeedUsage $usage)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update feed usages');
        }
        if ($usage->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed usage not found in this farm');
        }
        $validator = Validator::make($request->all(), [
            'poultry_feed_inventory_id' => 'sometimes|required|exists:poultry_feed_inventories,id',
            'poultry_feed_type_id' => 'sometimes|required|exists:poultry_feed_types,id',
            'flock_id' => 'sometimes|required|exists:flocks,id',
            'quantity' => 'sometimes|numeric|min:0',
            'unit_cost' => 'sometimes|numeric|min:0',
            'usage_date' => 'sometimes|date',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $usage->update($request->all());
        return $this->sendResponse($usage, 'Feed usage updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryFeedUsage $usage)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete feed usages');
        }
        if ($usage->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed usage not found in this farm');
        }
        $usage->delete();
        return $this->sendResponse(null, 'Feed usage deleted successfully');
    }

    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed usages');
        }
        $query = PoultryFeedUsage::where('farm_id', $farm->id);
        $statistics = [
            'total_feed_usages' => $query->count(),
            'total_quantity' => $query->sum('quantity'),
            'by_feed_type' => $query->selectRaw('poultry_feed_type_id, sum(quantity) as total_quantity')->groupBy('poultry_feed_type_id')->get(),
        ];
        return $this->sendResponse($statistics, 'Feed usage statistics retrieved successfully');
    }
} 