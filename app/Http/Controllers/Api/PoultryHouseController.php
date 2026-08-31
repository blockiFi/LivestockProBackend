<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PoultryHouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Traits\RegisterEvents;
use App\Models\Farm;
use App\Models\PoultryHouseCapacityRule;
use App\Models\Flock;
use App\Services\HouseCapacityService;
use App\Services\HouseStatusService;
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

        // Always scope houses to this farm
        $query = PoultryHouse::where('farm_id', $farm->id);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        if ($pagination) {
            $houses = $query->with(['poultryType' ,'literType'])->paginate(10);
        } else {
            $houses = $query->with(['poultryType' ,'literType'])->get();
        }

        $capacityService = app(HouseCapacityService::class);
        $houses->each(function (PoultryHouse $house) use ($farm, $capacityService) {
            $house->setAttribute(
                'current_occupancy',
                $capacityService->currentOccupancyForHouse((int) $farm->id, (int) $house->id)
            );
        });

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
            // Ignore soft-deleted houses when checking name uniqueness.
            'name' => 'required|string|max:255|unique:poultry_houses,name,NULL,id,farm_id,' . $farm->id . ',deleted_at,NULL',
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
            app(HouseStatusService::class)->recalculateForHouse((int) $farm->id, (int) $house->id);
            $house->refresh();
            $this->RegisterEvent(
                eventType: 'poultry_house_created',
                tableName: 'poultry_houses',
                tableId: $house->id,
                farmId: $farm->id
            );
            // include related types in API response
            $house->load(['poultryType', 'literType']);
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
        $house = PoultryHouse::with(['poultryType','literType'])
            ->where('farm_id', $farm->id)
            ->findOrFail($id);
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
     
        $house = PoultryHouse::where('farm_id', $farm->id)->find($id);
        if (!$house) {
            return $this->sendError("Poultry house with ID: {$id} not found", [], 404);
        }
        $validator = Validator::make($request->all(), [
            // Ignore soft-deleted houses when checking name uniqueness.
            'name' => 'sometimes|string|max:255|unique:poultry_houses,name,' . $id . ',id,farm_id,' . $farm->id . ',deleted_at,NULL',
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
            // refresh relationships for response
            $house->load(['poultryType', 'literType']);
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

    /**
     * Get age-based capacity rules for a house.
     */
    public function capacityRules($farmId, $houseId)
    {
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view poultry houses', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view poultry houses', [], 403);
        }

        $house = PoultryHouse::where('farm_id', $farm->id)->findOrFail($houseId);

        $rules = PoultryHouseCapacityRule::where('farm_id', $farm->id)
            ->where('house_id', $house->id)
            ->orderBy('min_age_days')
            ->get();

        return $this->sendResponse($rules, 'Capacity rules retrieved successfully');
    }

    /**
     * Replace age-based capacity rules for a house.
     * Payload: { rules: [{ min_age_days, max_age_days, capacity }] }
     */
    public function updateCapacityRules(Request $request, $farmId, $houseId)
    {
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage poultry houses', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage poultry houses', [], 403);
        }

        $house = PoultryHouse::where('farm_id', $farm->id)->findOrFail($houseId);

        $validator = Validator::make($request->all(), [
            // Rules are optional: sending an empty array clears all age-based rules
            'rules' => 'required|array',
            'rules.*.min_age_days' => 'required|integer|min:0',
            // null => open-ended range (age >= min_age_days)
            'rules.*.max_age_days' => 'nullable|integer|min:0',
            'rules.*.capacity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $rules = $validator->validated()['rules'];

        // Validate ranges: min <= max and non-overlapping
        usort($rules, fn ($a, $b) => ((int) $a['min_age_days']) <=> ((int) $b['min_age_days']));

        $prevMax = null; // int|max or null for open-ended
        $hasPrev = false;
        foreach ($rules as $r) {
            $min = (int) $r['min_age_days'];
            $maxRaw = $r['max_age_days'] ?? null;
            $max = $maxRaw === null ? null : (int) $maxRaw;

            if ($max !== null && $min > $max) {
                return $this->sendError('Invalid rule range: min_age_days must be <= max_age_days (or null)', [], 422);
            }

            // Open-ended previous max means infinity: any subsequent rule would overlap.
            if ($hasPrev && $prevMax === null) {
                return $this->sendError('Capacity rule ranges must not overlap (open-ended range overlaps other rules)', [], 422);
            }

            if ($hasPrev && $prevMax !== null && $min <= $prevMax) {
                return $this->sendError('Capacity rule ranges must not overlap', [], 422);
            }

            $prevMax = $max;
            $hasPrev = true;
        }

        try {
            DB::beginTransaction();

            PoultryHouseCapacityRule::where('farm_id', $farm->id)
                ->where('house_id', $house->id)
                ->delete();

            foreach ($rules as $r) {
                $maxRaw = $r['max_age_days'] ?? null;
                $max = $maxRaw === null ? null : (int) $maxRaw;
                PoultryHouseCapacityRule::create([
                    'farm_id' => $farm->id,
                    'house_id' => $house->id,
                    'min_age_days' => (int) $r['min_age_days'],
                    'max_age_days' => $max,
                    'capacity' => (int) $r['capacity'],
                ]);
            }

            DB::commit();

            $out = PoultryHouseCapacityRule::where('farm_id', $farm->id)
                ->where('house_id', $house->id)
                ->orderBy('min_age_days')
                ->get();

            return $this->sendResponse($out, 'Capacity rules updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error updating capacity rules', [$e->getMessage()], 500);
        }
    }

    /**
     * Compute the allowed capacity for a given flock age in a given house.
     * GET /api/poultry-houses/{farmId}/{houseId}/allowed-capacity?flock_id={id}
     */
    public function allowedCapacity(Request $request, $farmId, $houseId, HouseCapacityService $capacityService)
    {
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view poultry houses', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view poultry houses', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'flock_id' => 'required|integer|exists:flocks,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $house = PoultryHouse::where('farm_id', $farm->id)->findOrFail($houseId);
        $flock = Flock::where('farm_id', $farm->id)->findOrFail((int) $request->query('flock_id'));

        $ageDays = $capacityService->flockAgeDays($flock);

        $matchedRule = $capacityService->capacityRuleForHouseAtAge($house, $ageDays);
        $defaultCapacity = (int) ($house->capacity ?? 0);
        $allowedCapacity = $matchedRule ? (int) $matchedRule->capacity : $defaultCapacity;

        $currentOccupancy = $capacityService->currentOccupancyForHouse((int) $farm->id, (int) $house->id);
        $incoming = (int) $flock->quantity;
        $attemptedOccupancy = $currentOccupancy + $incoming;

        return $this->sendResponse([
            'flock_id' => (int) $flock->id,
            'age_days' => $ageDays,
            'default_capacity' => $defaultCapacity,
            'allowed_capacity' => $allowedCapacity,
            'matched_rule' => $matchedRule ? [
                'id' => (int) $matchedRule->id,
                'min_age_days' => (int) $matchedRule->min_age_days,
                'max_age_days' => $matchedRule->max_age_days === null ? null : (int) $matchedRule->max_age_days,
                'capacity' => (int) $matchedRule->capacity,
            ] : null,
            'is_fallback_default' => $matchedRule ? false : true,
            'current_occupancy' => $currentOccupancy,
            'flock_size' => $incoming,
            'attempted_occupancy' => $attemptedOccupancy,
            'matched_band' => $capacityService->formatAgeBand($matchedRule),
        ], 'Allowed capacity computed successfully');
    }
}
