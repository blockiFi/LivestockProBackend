<?php

namespace App\Http\Controllers\Api;

use App\Models\Flock;
use App\Models\Farm;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\FlockStage;
use App\Models\FlockDailyRecord;
use App\Models\PoultryEvent;
use App\Traits\RegisterEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class FlockController extends ApiController
{
    use RegisterEvents;

    /**
     * Display a listing of the flocks for a specific farm.
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

        if (!auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }

        $query = Flock::with(['poultryType', 'flockStage', 'poultryHouse'])
            ->where('farm_id', $farmId)
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->search, function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%")
                    ->orWhere('batch_number', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc');

        // Check if pagination is requested
        if ($paginated === 'paginated' || $request->has('page') || $request->has('perPage')) {
            $perPage = (int) ($request->perPage ?? 10);
            $page = (int) ($request->page ?? 1);
            
            // Get total count to validate page number
            $totalCount = $query->count();
            $lastPage = (int) ceil($totalCount / $perPage);
            
            // If requesting a page beyond the last page and total count > 0, redirect to last page
            if ($page > $lastPage && $totalCount > 0) {
                return $this->sendError('Requested page does not exist. Last available page is ' . $lastPage, [
                    'requested_page' => $page,
                    'last_page' => $lastPage,
                    'total_items' => $totalCount,
                    'per_page' => $perPage
                ], 400);
            }
            
            $flocks = $query->paginate($perPage);
        } else {
            $flocks = $query->get();
        }

        // Update flock stages
        foreach ($flocks as $flock) {
            $this->updateFlockStage($flock);
        }

        return $this->sendResponse($flocks, 'Flocks retrieved successfully');
    }

    /**
     * Store a newly created flock in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|exists:farms,id',
            'house_id' => 'required|exists:poultry_houses,id',
            'poultry_type_id' => 'required|exists:poultry_types,id',
            'flock_stage_id' => 'required|exists:flock_stages,id',
            'batch_number' => 'string|max:255',
            'name' => 'required|string|max:255',
            'breed' => 'string|max:255',
            'source' => 'string|max:255',
            'quantity' => 'required|integer|min:1',
            'arrival_date' => 'required|date',
            'arrival_age_days' => 'required|integer|min:0',
            'expected_end_date' => 'nullable|date|after:arrival_date',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid flock data', $validator->errors()->all());
        }

        $farm = Farm::findOrFail($request->farm_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage flocks', [], 403);
        }

        try {
            DB::beginTransaction();
            // Check if the house has enough capacity
            $house = PoultryHouse::findOrFail($request->house_id);
            $currentOccupancy = Flock::where('house_id', $request->house_id)
                ->where('status', 'active')
                ->sum('quantity');
            
            if (($currentOccupancy + $request->quantity) > $house->capacity) {
                return $this->sendError(
                    'House capacity exceeded. Current occupancy: ' . $currentOccupancy . 
                    ', New flock: ' . $request->quantity . 
                    ', House capacity: ' . $house->capacity,
                    [],
                    422
                );
            }

            // Determine the appropriate flock stage based on poultry type and age
            $flockStage = FlockStage::where('poultry_type_id', $request->poultry_type_id)
                ->where('from_age', '<=', $request->arrival_age_days)
                ->where('to_age', '>=', $request->arrival_age_days)
                ->first();

            if (!$flockStage) {
                return $this->sendError(
                    'No suitable flock stage found for the given poultry type and age',
                    [],
                    422
                );
            }

            $request->merge(['flock_stage_id' => $flockStage->id]);
            $flock = Flock::create([
                'farm_id' => $request->farm_id,
                'house_id' => $request->house_id,
                'poultry_type_id' => $request->poultry_type_id,
                'flock_stage_id' => $request->flock_stage_id,
                'name' => $request->name,
                'batch_number' => $request->batch_number,
                'breed' => $request->breed,
                'source' => $request->source,
                'quantity' => $request->quantity,
                'arrival_date' => $request->arrival_date,
                'arrival_age_days' => $request->arrival_age_days,
                'status' => 'active',
                'batch_number' => 'FLK-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT),
                'expected_end_date' => $request->expected_end_date,
                'notes' => $request->notes
            ]);
            // Update the house status to 'active' as it was empty before flock addition
            if ($house->status !== 'active') {
                $house->status = 'active';
                $house->save();
            }
            // Register the event
            $this->RegisterEvent(
                $request->farm_id,
                $flock->id,
                'flock_creation',
                'flock',
                $flock->id
            );

            DB::commit();
            // Verify batch number uniqueness
            $batchNumber = $flock->batch_number;
            $attempts = 0;
            $maxAttempts = 10;

            while (Flock::where('batch_number', $batchNumber)->where('id', '!=', $flock->id)->exists() && $attempts < $maxAttempts) {
                $batchNumber = 'FLK-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                $attempts++;
            }

            if ($attempts >= $maxAttempts) {
                throw new \Exception('Failed to generate unique batch number after ' . $maxAttempts . ' attempts');
            }

            if ($batchNumber !== $flock->batch_number) {
                $flock->update(['batch_number' => $batchNumber]);
            }
            return $this->sendResponse(
                $flock->load(['poultryType', 'flockStage', 'poultryHouse']),
                'Flock created successfully',
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to create flock: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified flock.
     */
    public function show($FarmId , $flockID )
    {
        $flock = Flock::with([
            'poultryType',
            'flockStage',
            'poultryHouse',
            'dailyRecords',
            'mortalityReports',
            'weightReports',
            'eggReports',
            'BatchVaccinationSchedules.schedule',
            'BatchVaccinationSchedules.schedule.items',
            'BatchVaccinationSchedules.items.scheduleItem',
            'BatchVaccinationSchedules.items.scheduleItem',
            'BatchMedicationSchedules.schedule',
            'BatchMedicationSchedules.schedule.items',
            'BatchMedicationSchedules.items.scheduleItem',
            'batchFeedingSchedules.schedule',
            'batchFeedingSchedules.schedule.items',
            'batchFeedingSchedules.items.scheduleItem',
            'poultryFeedUsages.feedInventory.feedType',
            'poultryFeedUsages.feedType',
            'poultryFeedUsages.flock',
            'poultryMedicationRecords.medication',
            'poultryMedicationRecords.medicationInventory',
            'poultryMedicationRecords.administrationMethod',
            'poultryVaccinationRecords.vaccine',
            'poultryVaccinationRecords.vaccineInventory',
            'poultryVaccinationRecords.administrationMethod'
        ])->findOrFail($flockID);

        $farm = Farm::findOrFail($FarmId);
        if ($flock->farm_id != $farm->id) {
            return $this->sendError('Flock does not belong to the specified farm', [], 404);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }
        $this->updateFlockStage($flock);
        return $this->sendResponse($flock, 'Flock retrieved successfully');
    }

    /**
     * Update the specified flock in storage.
     */
    public function update(Request $request, $id)
    {
        $flock = Flock::findOrFail($id);

        $farm = Farm::findOrFail($flock->farm_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage flocks', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'house_id' => 'sometimes|required|exists:poultry_houses,id',
            'poultry_type_id' => 'sometimes|required|exists:poultry_types,id',
            'breed' => 'sometimes|required|string|max:255',
            'source' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:1',
            'arrival_date' => 'sometimes|required|date',
            'arrival_age_days' => 'sometimes|required|integer|min:0',
            'status' => 'sometimes|required|in:active,sold,culled,completed',
            'expected_end_date' => 'nullable|date|after:arrival_date',
            'actual_end_date' => 'nullable|date|after:arrival_date',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid flock data', $validator->errors()->toArray());
        }

        try {
            DB::beginTransaction();

            $flock->update($request->all());

            // Register the event
            $this->RegisterEvent(
                $flock->farm_id,
                $flock->id,
                'flock_update',
                'flock',
                $flock->id
            );

            DB::commit();
            $this->updateFlockStage($flock);
            return $this->sendResponse(
                $flock->load(['poultryType', 'flockStage', 'poultryHouse']),
                'Flock updated successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to update flock: ' . $e->getMessage());
        }
    }

    /**
     * Remove the specified flock from storage.
     */
    public function destroy($id)
    {
        $flock = Flock::findOrFail($id);

        $farm = Farm::findOrFail($flock->farm_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage flocks', [], 403);
        }

        try {
            DB::beginTransaction();

            // Register the event before deletion
            $this->RegisterEvent(
                $flock->farm_id,
                $flock->id,
                'flock_deletion',
                'flock',
                $flock->id
            );

            $flock->delete();

            DB::commit();

            return $this->sendResponse(null, 'Flock deleted successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to delete flock: ' . $e->getMessage());
        }
    }

    /**
     * Get flock statistics.
     */
    public function getStatistics($farm, $id)
    {
        $flock = Flock::findOrFail($id);

        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm);

        if (!auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }

        $statistics = [
            'total_mortality' => $flock->mortalityReports()->sum('mortality_count'),
            'total_eggs' => $flock->eggReports()->sum('eggs_collected'),
            'average_weight' => $flock->weightReports()->avg('average_weight'),
            'total_feed_consumed' => $flock->poultryFeedUsages()->sum('quantity'),
            'total_medications' => $flock->poultryMedicationRecords()->count(),
            'current_count' => $flock->quantity - $flock->mortalityReports()->sum('mortality_count'),
            'production_rate' => $flock->eggReports()->avg('production_percentage')
        ];

        return $this->sendResponse($statistics, 'Flock statistics retrieved successfully');
    }

    /**
     * Get flock timeline.
     */
    public function getTimeline($farm,$id)
    {
        $flock = Flock::findOrFail($id);

        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }

        $timeline = PoultryEvent::where('flock_id', $id)
            ->orderBy('event_date', 'desc')
            ->get();

        return $this->sendResponse($timeline, 'Flock timeline retrieved successfully');
    }

    /**
     * Update flock status.
     */
    public function updateStatus(Request $request,$farm, $id)
    {
        $flock = Flock::findOrFail($id);

        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage flocks', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,sold,culled,completed',
            'actual_end_date' => 'required_if:status,sold,culled,completed|date'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid status data', $validator->errors()->toArray());
        }

        try {
            DB::beginTransaction();

            $flock->update([
                'status' => $request->status,
                'actual_end_date' => $request->actual_end_date
            ]);

            // Register the event
            $this->RegisterEvent(
                $flock->farm_id,
                $flock->id,
                'flock_status_update',
                'flock',
                $flock->id
            );

            DB::commit();

            return $this->sendResponse($flock, 'Flock status updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to update flock status: ' . $e->getMessage());
        }
    }

    /**
     * Get flock performance metrics.
     */
    public function getPerformanceMetrics($farm ,$id)
    {
        $flock = Flock::findOrFail($id);

        $farm = Farm::findOrFail($farm);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }

        $metrics = [
            'mortality_rate' => $this->calculateMortalityRate($flock),
            'feed_conversion_ratio' => $this->calculateFeedConversionRatio($flock),
            'egg_production_rate' => $this->calculateEggProductionRate($flock),
            'weight_gain_rate' => $this->calculateWeightGainRate($flock)
        ];

        return $this->sendResponse($metrics, 'Flock performance metrics retrieved successfully');
    }

    /**
     * Calculate mortality rate.
     */
    private function calculateMortalityRate($flock)
    {
        $totalMortality = $flock->mortalityReports()->sum('mortality_count');
        $initialCount = $flock->quantity;
        
        return $initialCount > 0 ? ($totalMortality / $initialCount) * 100 : 0;
    }

    /**
     * Calculate feed conversion ratio.
     */
    private function calculateFeedConversionRatio($flock)
    {
        $totalFeed = $flock->poultryFeedUsages()->sum('quantity');
        $totalWeightGain = $flock->weightReports()->max('average_weight') - 
                          $flock->weightReports()->min('average_weight');
        
        return $totalWeightGain > 0 ? $totalFeed / $totalWeightGain : 0;
    }

    /**
     * Calculate egg production rate.
     */
    private function calculateEggProductionRate($flock)
    {
        return $flock->eggReports()->avg('production_percentage') ?? 0;
    }

    /**
     * Calculate weight gain rate.
     */
    private function calculateWeightGainRate($flock)
    {
        $firstWeight = $flock->weightReports()->orderBy('report_date', 'asc')->first();
        $lastWeight = $flock->weightReports()->orderBy('report_date', 'desc')->first();
        
        if (!$firstWeight || !$lastWeight) {
            return 0;
        }

        $weightGain = $lastWeight->average_weight - $firstWeight->average_weight;
        $days = $lastWeight->report_date->diffInDays($firstWeight->report_date);
        
        return $days > 0 ? $weightGain / $days : 0;
    }

    /**
     * Update flock stage based on current age.
     */
    private function updateFlockStage($flock)
    {
        $currentAge = now()->diffInDays($flock->arrival_date) + $flock->arrival_age_days;
        
        $newStage = FlockStage::where('poultry_type_id', $flock->poultry_type_id)
            ->where('from_age', '<=', $currentAge)
            ->where('to_age', '>=', $currentAge)
            ->first();

        if ($newStage && $newStage->id !== $flock->flock_stage_id) {
            $flock->update(['flock_stage_id' => $newStage->id]);
            
            // Register the stage change event
            $this->RegisterEvent(
                $flock->farm_id,
                $flock->id,
                'flock_stage_change',
                'flock',
                $flock->id,
                [
                    'old_stage' => $flock->flockStage->name,
                    'new_stage' => $newStage->name,
                    'age_days' => $currentAge
                ]
            );
        }
    }
}
