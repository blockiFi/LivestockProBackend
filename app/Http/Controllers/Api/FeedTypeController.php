<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\PoultryFeedType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FeedTypeController extends ApiController
{
    public function index(Request $request, $farm , $poultryType = null , $pagination = null)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed types', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed types');
        }
        $query = PoultryFeedType::where(function($q) use ($farm) {
            $q->where('farm_id', $farm->id)
              ->orWhere('type', 'default');
        });
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        if ($poultryType) {
         
            $query->where('poultry_type_id', $poultryType);
        }

        $sortField = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        if ($pagination) {
            $perPage = $request->input('per_page', 10);
            $feedTypes = $query->paginate($perPage);
        } else {
            $feedTypes = $query->get();
        }
        return $this->sendResponse($feedTypes, 'Feed types retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create feed types', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create feed types');
        }
        $validator = Validator::make($request->all(), [
            'poultry_type_id' => 'required|exists:poultry_types,id',
            'name' => 'required|string|max:255|unique:poultry_feed_types,name',
            'description' => 'nullable|string',
            'start_age' => 'required|integer|min:0',
            'end_age' => 'required|integer|min:0|gte:start_age',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $feedType = PoultryFeedType::create([
            'farm_id' => $farm->id,
            'type' => 'user',
            'poultry_type_id' => $request->poultry_type_id,
            'name' => $request->name,
            'description' => $request->description,
            'start_age' => $request->start_age,
            'end_age' => $request->end_age,
        ]);
        return $this->sendResponse($feedType, 'Feed type created successfully', 201);
    }

    public function show(Request $request, $farm, PoultryFeedType $feedType)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed types', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed types');
        }
        if ($feedType->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed type not found in this farm');
        }
        // Load the related poultry type for the feed type
        $feedType->load('poultryType');
        return $this->sendResponse($feedType, 'Feed type retrieved successfully');
    }

    public function update(Request $request, $farm, PoultryFeedType $feedType)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update feed types', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update feed types');
        }
        if ($feedType->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed type not found in this farm');
        }
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('poultry_feed_types')->ignore($feedType->id)],
            'type' => ['sometimes', 'required', Rule::in(['default', 'user'])],
            'description' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $feedType->update($request->all());
        return $this->sendResponse($feedType, 'Feed type updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryFeedType $feedType)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete feed types', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete feed types');
        }
        if ($feedType->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed type not found in this farm');
        }
        $feedType->delete();
        return $this->sendResponse(null, 'Feed type deleted successfully');
    }

    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed types', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed types');
        }
        $query = PoultryFeedType::where(function($q) use ($farm) {
            $q->where('farm_id', $farm->id)
              ->orWhere('type', 'default');
        });
        $statistics = [
            'total_feed_types' => $query->count(),
            'by_type' => $query->selectRaw('type, count(*) as count')->groupBy('type')->get(),
        ];
        return $this->sendResponse($statistics, 'Feed type statistics retrieved successfully');
    }
} 