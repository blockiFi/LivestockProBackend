<?php

namespace App\Http\Controllers\Api;

use App\Models\PoultryType;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class PoultryTypeController extends ApiController
{
    /**
     * Display a listing of poultry types for a specific farm.
     */
    public function index(Request $request, $farmId)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }

        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view poultry types', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view poultry types', [], 403);
        }

        $poultryTypes = PoultryType::all();

        return $this->sendResponse($poultryTypes, 'Poultry types retrieved successfully');
    }

    /**
     * Store a newly created poultry type.
     */
    public function store(Request $request, $farmId)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('create poultry types', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to create poultry types', [], 403);
        }

        // Check if poultry type with same name already exists in this farm
        $existingPoultryType = PoultryType::where('farm_id', $farmId)
            ->where('name', $request->name)
            ->first();

        if ($existingPoultryType) {
            return $this->sendError('A poultry type with this name already exists in this farm', [], 422);
        }

        DB::beginTransaction();
        try {
            $poultryType = PoultryType::create([
                'farm_id' => $farmId,
                'name' => $request->name,
                'type' => $request->type,
                'description' => $request->description,
                'status' => $request->status,
                'created_by' => auth()->id(),
            ]);

            $poultryType->load(['createdBy']);

            DB::commit();
            return $this->sendResponse($poultryType, 'Poultry type created successfully', 201);
        } catch (\Exception $e) {
            DB::rollback();
            return $this->sendError('Failed to create poultry type: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Display the specified poultry type.
     */
    public function show(Request $request, $farmId, PoultryType $poultryType)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }

        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view poultry types', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view poultry types', [], 403);
        }

        if ($poultryType->farm_id !== $farmId) {
            return $this->sendError('Poultry type not found in this farm', [], 404);
        }

        $poultryType->load(['createdBy', 'flocks', 'poultryHouses', 'feedTypes']);

        return $this->sendResponse($poultryType, 'Poultry type retrieved successfully');
    }

    /**
     * Update the specified poultry type.
     */
    public function update(Request $request, $farmId, PoultryType $poultryType)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('update poultry types', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to update poultry types', [], 403);
        }

        if ($poultryType->farm_id !== $farmId) {
            return $this->sendError('Poultry type not found in this farm', [], 404);
        }

        // Check if poultry type with same name already exists in this farm (excluding current one)
        if ($request->has('name')) {
            $existingPoultryType = PoultryType::where('farm_id', $farmId)
                ->where('name', $request->name)
                ->where('id', '!=', $poultryType->id)
                ->first();

            if ($existingPoultryType) {
                return $this->sendError('A poultry type with this name already exists in this farm', [], 422);
            }
        }

        DB::beginTransaction();
        try {
            $poultryType->update($request->only(['name', 'type', 'description', 'status']));
            $poultryType->load(['createdBy']);

            DB::commit();
            return $this->sendResponse($poultryType, 'Poultry type updated successfully');
        } catch (\Exception $e) {
            DB::rollback();
            return $this->sendError('Failed to update poultry type: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Remove the specified poultry type.
     */
    public function destroy(Request $request, $farmId, PoultryType $poultryType)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }

        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('delete poultry types', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to delete poultry types', [], 403);
        }

        if ($poultryType->farm_id !== $farmId) {
            return $this->sendError('Poultry type not found in this farm', [], 404);
        }

        // Check if poultry type is being used by flocks
        if ($poultryType->flocks()->count() > 0) {
            return $this->sendError('Cannot delete poultry type that is being used by flocks', [], 400);
        }

        // Check if poultry type is being used by poultry houses
        if ($poultryType->poultryHouses()->count() > 0) {
            return $this->sendError('Cannot delete poultry type that is being used by poultry houses', [], 400);
        }

        // Check if poultry type is being used by feed types
        if ($poultryType->feedTypes()->count() > 0) {
            return $this->sendError('Cannot delete poultry type that is being used by feed types', [], 400);
        }

        DB::beginTransaction();
        try {
            $poultryType->delete();
            DB::commit();
            return $this->sendResponse(null, 'Poultry type deleted successfully');
        } catch (\Exception $e) {
            DB::rollback();
            return $this->sendError('Failed to delete poultry type: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Get statistics for poultry types in a farm.
     */
    public function statistics(Request $request, $farmId)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }

        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view poultry types', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view poultry type statistics', [], 403);
        }

        $totalPoultryTypes = PoultryType::where('farm_id', $farmId)->count();
        $activePoultryTypes = PoultryType::where('farm_id', $farmId)->where('status', 'active')->count();
        $inactivePoultryTypes = PoultryType::where('farm_id', $farmId)->where('status', 'inactive')->count();

        // Get poultry types with their usage counts
        $poultryTypesWithUsage = PoultryType::where('farm_id', $farmId)
            ->withCount(['flocks', 'poultryHouses', 'feedTypes'])
            ->get();

        $statistics = [
            'total_poultry_types' => $totalPoultryTypes,
            'active_poultry_types' => $activePoultryTypes,
            'inactive_poultry_types' => $inactivePoultryTypes,
            'poultry_types_with_usage' => $poultryTypesWithUsage,
        ];

        return $this->sendResponse($statistics, 'Poultry type statistics retrieved successfully');
    }
}
