<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PoultryHouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Traits\RegisterEvents;
use App\Models\Farm;
use Spatie\Permission\PermissionRegistrar;
class PoultryHouseController extends ApiController
{
    use RegisterEvents;

    /**
     * Display a listing of poultry houses.
     */
    public function index(Request $request, $farm , $pagination = null)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view poultry houses', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view poultry houses', [], 403);
        }

        $query = PoultryHouse::query();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        if ($request->has('farm_id')) {
            $query->where('farm_id', $request->farm_id);
        }
        if ($pagination) {
        $houses = $query->with('poultryType' ,'literType')->paginate(10);
        } else {
            $houses = $query->with('poultryType' ,'literType')->get();
        }
        return $this->sendResponse($houses, 'Poultry houses retrieved successfully');
    }

    /**
     * Store a newly created poultry house.
     */
    public function store(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage poultry houses', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage poultry houses', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:poultry_houses,name,NULL,id,farm_id,' . $request->farm_id,
            'poultry_type_id' => 'required|exists:poultry_types,id',
            'liter_type_id' => 'required|integer|exists:liter_types,id',
            'capacity' => 'required|integer|min:1',
            'dimensions' => 'nullable|string',
            'construction_date' => 'nullable|date',
            'last_maintenance_date' => 'nullable|date',
            'status' => 'required|in:active,inactive,maintenance,empty',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        try {
            DB::beginTransaction();
            $house = PoultryHouse::create(array_merge($request->all(), ['farm_id' => $farm->id]));
            $this->RegisterEvent(
                eventType: 'poultry_house_created',
                tableName: 'poultry_houses',
                tableId: $house->id,
                farmId: $farm->id
            );
            DB::commit();
            return $this->sendResponse($house, 'Poultry house created successfully', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error creating poultry house', [$e->getMessage()], 500);
        }
    }

    /**
     * Display the specified poultry house.
     */
    public function show($farm, $id)
    {

        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
       
        if (!auth()->user()->can('view poultry houses', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view poultry houses', [], 403);
        }
        $house = PoultryHouse::findOrFail($id);
        return $this->sendResponse($house, 'Poultry house retrieved successfully');
    }

    /**
     * Update the specified poultry house.
     */
    public function update(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
        
        if (!auth()->user()->can('manage poultry houses', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage poultry houses', [], 403);
        }
     
         $house = PoultryHouse::find($id);
        if (!$house) {
            return $this->sendError("Poultry house with ID: {$id} not found", [], 404);
        }
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:poultry_houses,name,',
            'poultry_type_id' => 'sometimes|exists:poultry_types,id',
            'liter_type' => 'nullable|string|max:255',
            'capacity' => 'sometimes|integer|min:1',
            'dimensions' => 'nullable|string|max:255',
            'construction_date' => 'nullable|date',
            'last_maintenance_date' => 'nullable|date',
            'status' => 'sometimes|in:active,inactive',
            'notes' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }
        
        try {
            DB::beginTransaction();
            $house->update($request->all());
            $this->RegisterEvent(
                eventType: 'poultry_house_updated',
                tableName: 'poultry_houses',
                tableId: $house->id,
                farmId: $farm->id
            );
            DB::commit();
            return $this->sendResponse($house, 'Poultry house updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error updating poultry house', [$e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified poultry house.
     */
    public function destroy($farmId, $id)
    {
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage poultry houses', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage poultry houses', [], 403);
        }
        try {
            DB::beginTransaction();
            $house = PoultryHouse::findOrFail($id);
            $this->RegisterEvent(
                eventType: 'poultry_house_deleted',
                tableName: 'poultry_houses',
                tableId: $house->id,
                farmId: $farm->id
            );
            $house->delete();
            DB::commit();
            return $this->sendResponse(null, 'Poultry house deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error deleting poultry house', [$e->getMessage()], 500);
        }
    }

    /**
     * Get statistics for a poultry house.
     */
    public function getStatistics($farmId, $id)
    {
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view poultry houses', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view poultry houses', [], 403);
        }
        $house = PoultryHouse::findOrFail($id);
        $statistics = [
            'total_flocks' => $house->flocks()->count(),
            'active_flocks' => $house->flocks()->where('status', 'active')->count(),
            'capacity' => $house->capacity,
        ];
        return $this->sendResponse($statistics, 'Poultry house statistics retrieved successfully');
    }
}
