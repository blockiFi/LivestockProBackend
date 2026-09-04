<?php

namespace App\Http\Controllers\Api;

use App\Models\Flock;
use App\Models\Farm;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\FlockStage;
use App\Models\FlockDailyRecord;
use App\Models\PoultryEvent;
use App\Models\PoultryMortalityReport;
use App\Models\FlockHouseAllocation;
use App\Services\FarmEntitlementService;
use App\Services\HouseCapacityService;
use App\Services\HouseStatusService;
use App\Services\FlockFcrService;
use App\Traits\RegisterEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class FlockController extends ApiController
{
    use RegisterEvents;

    public function __construct(protected FlockFcrService $flockFcrService)
    {
    }

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
    public function store(Request $request, HouseCapacityService $capacityService)
    {

            $validator = Validator::make($request->all(), [
                'farm_id' => 'required|exists:farms,id',
                'house_id' => 'required|exists:poultry_houses,id',
                'poultry_type_id' => 'required|exists:poultry_types,id',
                'flock_stage_id' => 'required|exists:flock_stages,id',
                // 'batch_number' => 'string|max:255', // Do not accept from frontend
                'name' => 'required|string|max:255',
                'breed' => 'string|max:255',
                'source' => 'string|max:255',
                'quantity' => 'required|integer|min:1',
                'arrival_date' => 'required|date',
                'arrival_age_days' => 'required|integer|min:0',
                'expected_end_date' => 'nullable|date|after:arrival_date',
                'notes' => 'nullable|string',
                'medication_schedule_id' => 'nullable|exists:schedules,id',
                'vaccination_schedule_id' => 'nullable|exists:schedules,id',
                'feeding_schedule_id' => 'nullable|exists:feeding_schedules,id',
            ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Invalid flock data', $validator->errors()->all());
        }

        $farm = Farm::findOrFail($request->farm_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage flocks', [], 403);
        }

        if ($response = $this->ensureEntitled($farm, FarmEntitlementService::ACTION_CREATE_ACTIVE_FLOCK)) {
            return $response;
        }

        try {
            DB::beginTransaction();
            // Check if the house has enough capacity
            $house = PoultryHouse::findOrFail($request->house_id);
            // Use allocations for occupancy (multi-pen), fallback to old house capacity if no rules.
            $currentOccupancy = $capacityService->currentOccupancyForHouse((int) $farm->id, (int) $house->id);

            // Capacity is age-based, driven by flock age at creation time (arrival age).
            // IMPORTANT: for backdated records, we must evaluate age at the provided arrival_date,
            // so that age == arrival_age_days (not "today - arrival_date + arrival_age_days").
            $tempFlock = new Flock([
                'arrival_date' => $request->arrival_date,
                'arrival_age_days' => $request->arrival_age_days,
            ]);
            $ageDays = $capacityService->flockAgeDays($tempFlock, \Carbon\Carbon::parse($request->arrival_date));
            $cap = $capacityService->capacityForHouseAtAge($house, $ageDays);
            $capRule = $capacityService->capacityRuleForHouseAtAge($house, $ageDays);
            $band = $capacityService->formatAgeBand($capRule);

            if (($currentOccupancy + (int) $request->quantity) > $cap) {
                DB::rollBack();
                $houseName = $house->name ?? ('House #' . $house->id);
                $matchText = $capRule
                    ? (" (Matched capacity band: {$band})")
                    : (" (No capacity band matched; using default capacity)");
                return $this->sendError(
                    'House capacity exceeded for this flock age. ' .
                    'House: ' . $houseName .
                    ', Age: ' . $ageDays . ' days' . $matchText .
                    ', Allowed: ' . $cap .
                    ', Current occupancy: ' . $currentOccupancy .
                    ', Incoming: ' . $request->quantity .
                    ', Attempted occupancy: ' . ($currentOccupancy + (int) $request->quantity),
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
                DB::rollBack();
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
                // 'batch_number' => $request->batch_number, // Do not accept from frontend
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

            // Create initial allocation (multi-pen support)
            FlockHouseAllocation::updateOrCreate(
                ['flock_id' => $flock->id, 'house_id' => (int) $request->house_id],
                ['farm_id' => (int) $request->farm_id, 'quantity' => (int) $request->quantity]
            );
            // Keep house status in sync with the actual birds allocation.
            app(HouseStatusService::class)->recalculateForHouse((int) $request->farm_id, (int) $request->house_id);
            // Register the event
            $this->RegisterEvent(
                $request->farm_id,
                $flock->id,
                'flock_creation',
                'flock',
                $flock->id
            );

                // Generate batch schedules if schedule IDs are provided
                if ($request->medication_schedule_id) {
                    $medBatchSchedule = \App\Models\BatchSchedule::create([
                        'farm_id' => $request->farm_id,
                        'flock_id' => $flock->id,
                        'schedule_id' => $request->medication_schedule_id,
                    ]);
                    app(\App\Services\MedVacBatchScheduleItemGenerator::class)
                        ->generateForBatchSchedule($medBatchSchedule);
                }
                if ($request->vaccination_schedule_id) {
                    $vacBatchSchedule = \App\Models\BatchSchedule::create([
                        'farm_id' => $request->farm_id,
                        'flock_id' => $flock->id,
                        'schedule_id' => $request->vaccination_schedule_id,
                    ]);
                    app(\App\Services\MedVacBatchScheduleItemGenerator::class)
                        ->generateForBatchSchedule($vacBatchSchedule);
                }
                if ($request->feeding_schedule_id) {
                    \App\Models\FeedingBatchSchedule::create([
                        'farm_id' => $request->farm_id,
                        'flock_id' => $flock->id,
                        'feeding_schedule_id' => $request->feeding_schedule_id,
                        'status' => 'scheduled',
                    ]);
                }
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
            'dailyRecords' => fn ($query) => $query->orderByDesc('date'),
            'mortalityReports' => fn ($query) => $query->orderByDesc('date'),
            'weightReports.recordedBy:id,name',
            'eggReports.recordedBy:id,name',
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
            'poultryVaccinationRecords.administrationMethod',
            'flockExpenditures' => fn ($query) => $query->orderByDesc('date')->orderByDesc('id'),
            'flockSales'
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

        $payload = $flock->toArray();
        if ($flock->relationLoaded('dailyRecords')) {
            $payload['daily_records'] = $flock->dailyRecords
                ->map(fn ($record) => $record->toFrontendArray())
                ->values()
                ->all();
        }

        return $this->sendResponse($payload, 'Flock retrieved successfully');
    }

    /**
     * Update the specified flock in storage.
     */
    public function update(Request $request, $farm, $flock, HouseCapacityService $capacityService)
    {
        $farm = Farm::findOrFail($farm);
        $flock = Flock::where('farm_id', $farm->id)->findOrFail($flock);

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage flocks', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'house_id' => 'sometimes|required|exists:poultry_houses,id',
            'poultry_type_id' => 'sometimes|required|exists:poultry_types,id',
            'flock_stage_id' => 'sometimes|required|exists:flock_stages,id',
            'breed' => 'sometimes|required|string|max:255',
            'source' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:1',
            'arrival_date' => 'sometimes|required|date',
            'arrival_age_days' => 'sometimes|required|integer|min:0',
            'status' => 'sometimes|required|in:active,sold,culled,completed',
            'expected_end_date' => 'nullable|date|after:arrival_date',
            'actual_end_date' => 'nullable|date|after:arrival_date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid flock data', $validator->errors()->toArray());
        }

        try {
            DB::beginTransaction();

            $oldHouseId = (int) $flock->house_id;
            $newHouseId = (int) $request->input('house_id', $oldHouseId);
            $newQuantity = (int) $request->input('quantity', $flock->quantity);

            if ($request->has('house_id') || $request->has('quantity')) {
                $house = PoultryHouse::where('farm_id', $farm->id)->findOrFail($newHouseId);

                $arrivalDate = $request->input('arrival_date', $flock->arrival_date);
                $arrivalAgeDays = (int) $request->input('arrival_age_days', $flock->arrival_age_days);
                $tempFlock = new Flock([
                    'arrival_date' => $arrivalDate,
                    'arrival_age_days' => $arrivalAgeDays,
                ]);
                $ageDays = $capacityService->flockAgeDays(
                    $tempFlock,
                    \Carbon\Carbon::parse($arrivalDate)
                );
                $cap = $capacityService->capacityForHouseAtAge($house, $ageDays);
                $capRule = $capacityService->capacityRuleForHouseAtAge($house, $ageDays);
                $band = $capacityService->formatAgeBand($capRule);

                $currentOccupancy = $capacityService->currentOccupancyForHouse((int) $farm->id, $newHouseId);
                $existingAllocation = (int) FlockHouseAllocation::query()
                    ->where('farm_id', $farm->id)
                    ->where('house_id', $newHouseId)
                    ->where('flock_id', $flock->id)
                    ->sum('quantity');
                $occupancyExcludingThisFlock = max(0, $currentOccupancy - $existingAllocation);

                if (($occupancyExcludingThisFlock + $newQuantity) > $cap) {
                    DB::rollBack();
                    $houseName = $house->name ?? ('House #' . $house->id);
                    $matchText = $capRule
                        ? (" (Matched capacity band: {$band})")
                        : (" (No capacity band matched; using default capacity)");

                    return $this->sendError(
                        'House capacity exceeded for this flock age. ' .
                        'House: ' . $houseName .
                        ', Age: ' . $ageDays . ' days' . $matchText .
                        ', Allowed: ' . $cap .
                        ', Other occupancy: ' . $occupancyExcludingThisFlock .
                        ', Requested: ' . $newQuantity .
                        ', Attempted occupancy: ' . ($occupancyExcludingThisFlock + $newQuantity),
                        [],
                        422
                    );
                }
            }

            $flock->update($request->only([
                'name',
                'house_id',
                'poultry_type_id',
                'flock_stage_id',
                'breed',
                'source',
                'quantity',
                'arrival_date',
                'arrival_age_days',
                'status',
                'expected_end_date',
                'actual_end_date',
                'notes',
            ]));

            if ($request->has('house_id') || $request->has('quantity')) {
                FlockHouseAllocation::updateOrCreate(
                    ['flock_id' => $flock->id, 'house_id' => $newHouseId],
                    ['farm_id' => (int) $farm->id, 'quantity' => $newQuantity]
                );

                if ($newHouseId !== $oldHouseId) {
                    FlockHouseAllocation::where('flock_id', $flock->id)
                        ->where('house_id', '!=', $newHouseId)
                        ->delete();
                }

                app(HouseStatusService::class)->recalculateForHouse((int) $farm->id, $newHouseId);
                if ($newHouseId !== $oldHouseId) {
                    app(HouseStatusService::class)->recalculateForHouse((int) $farm->id, $oldHouseId);
                }
            }

            $this->RegisterEvent(
                $flock->farm_id,
                $flock->id,
                'flock_update',
                'flock',
                $flock->id
            );

            DB::commit();
            $this->updateFlockStage($flock->fresh());

            return $this->sendResponse(
                $flock->fresh()->load(['poultryType', 'flockStage', 'poultryHouse']),
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

            $affectedHouseIds = FlockHouseAllocation::query()
                ->where('farm_id', (int) $flock->farm_id)
                ->where('flock_id', (int) $flock->id)
                ->pluck('house_id')
                ->unique()
                ->values()
                ->all();

            // Legacy fallback: if allocations were never created for this flock.
            if (count($affectedHouseIds) === 0 && !empty($flock->house_id)) {
                $affectedHouseIds = [(int) $flock->house_id];
            }

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

            foreach ($affectedHouseIds as $houseId) {
                app(HouseStatusService::class)->recalculateForHouse((int) $flock->farm_id, (int) $houseId);
            }

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

        // Reopening an ended batch consumes an active-batch slot.
        if ($request->status === 'active' && $flock->status !== 'active') {
            if ($response = $this->ensureEntitled($farm, FarmEntitlementService::ACTION_CREATE_ACTIVE_FLOCK)) {
                return $response;
            }
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

            // Release (or re-occupy) pens based on remaining active occupancy.
            app(HouseStatusService::class)->recalculateForFlock($flock->fresh() ?? $flock);

            return $this->sendResponse($flock->fresh(), 'Flock status updated successfully');

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
        return $this->flockFcrService->compute($flock) ?? 0;
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

    /**     * Get the actual flock quantity after subtracting total mortality and total culling.
     */
    public function getActualQuantity($farmId, $flockId)
    {
        $flock = Flock::where('id', $flockId)
            ->where('farm_id', $farmId)
            ->first();

        if (!$flock) {
            return $this->sendError('Flock not found in this farm', [], 404);
        }

        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view flocks', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view flocks', [], 403);
        }

        // Total mortality from mortality reports
        $totalMortality = (int) PoultryMortalityReport::where('flock_id', $flockId)
            ->where('farm_id', $farmId)
            ->sum('mortality_count');

        // Total culling from daily records
        $totalCulling = (int) FlockDailyRecord::where('flock_id', $flockId)
            ->where('farm_id', $farmId)
            ->sum('culling_count');

        $originalQuantity = $flock->quantity;
        $actualQuantity = $originalQuantity - $totalMortality - $totalCulling;

        // Ensure it doesn't go below 0
        $actualQuantity = max(0, $actualQuantity);

        return $this->sendResponse([
            'flock_id' => (int) $flockId,
            'original_quantity' => $originalQuantity,
            'total_mortality' => $totalMortality,
            'total_culling' => $totalCulling,
            'actual_quantity' => $actualQuantity,
        ], 'Actual flock quantity retrieved successfully');
    }

    /**     * Update flock stage based on current age.
     */
    private function updateFlockStage($flock)
    {
        // Keep stage-age non-negative; if arrival_date is in the future, treat as 0 days since arrival.
        $daysSinceArrival = max(0, (int) now()->diffInDays($flock->arrival_date));
        $currentAge = $daysSinceArrival + (int) ($flock->arrival_age_days ?? 0);
        
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
