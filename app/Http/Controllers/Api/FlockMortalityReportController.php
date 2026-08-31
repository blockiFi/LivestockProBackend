<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\PoultryMortalityReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FlockMortalityReportController extends ApiController
{
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock mortality reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock mortality reports');
        }
        $query = PoultryMortalityReport::with(['flock', 'poultryType', 'creator'])->where('farm_id', $farm->id);
        if ($request->has('flock_id')) {
            $query->where('flock_id', $request->flock_id);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('date', 'like', "%{$search}%");
        }
        $sortField = $request->input('sort_by', 'date');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);
        $perPage = $request->input('per_page', 10);
        $reports = $query->paginate($perPage);
        return $this->sendResponse($reports, 'Flock mortality reports retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create flock mortality reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create flock mortality reports');
        }
        $validator = Validator::make($request->all(), [
            'flock_id' => 'required|exists:flocks,id',
            'mortality_count' => 'required|integer|min:0',
            'average_weight' => 'required|numeric|min:0',
            'bird_count' => 'nullable|integer|min:0',
            'mortality_percentage' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'date' => 'required|date',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        
        // Get the flock and its poultry type
        $flock = \App\Models\Flock::findOrFail($request->flock_id);

        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        $date = $request->date;
        $birdCountOnDate = $flock->birdCountOnDate($date);
        $mortalityCount = (int) $request->mortality_count;

        if ($mortalityCount > $birdCountOnDate) {
            return $this->sendValidationError('Validation failed', [
                'mortality_count' => ["Mortality count cannot exceed bird count on {$date} ({$birdCountOnDate})"],
            ]);
        }

        $mortalityPercentage = $birdCountOnDate > 0
            ? round(($mortalityCount / $birdCountOnDate) * 100, 2)
            : 0;
        
        $report = PoultryMortalityReport::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'poultry_type_id' => $flock->poultry_type_id,
            'mortality_count' => $mortalityCount,
            'average_weight' => $request->average_weight,
            'bird_count' => $birdCountOnDate,
            'mortality_percentage' => $mortalityPercentage,
            'notes' => $request->notes,
            'date' => $date,
            'recorded_by' => $user->id,
        ]);
        $flock->reconcileHouseAllocations();
        $report->load(['flock', 'poultryType', 'creator']);
        return $this->sendResponse($report, 'Flock mortality report created successfully', 201);
    }

    public function show(Request $request, $farm, PoultryMortalityReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock mortality reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock mortality reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock mortality report not found in this farm');
        }
        $report->load(['flock', 'poultryType', 'creator']);
        return $this->sendResponse($report, 'Flock mortality report retrieved successfully');
    }

    public function update(Request $request, $farm, PoultryMortalityReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update flock mortality reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update flock mortality reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock mortality report not found in this farm');
        }

        $flock = \App\Models\Flock::find($report->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'flock_id' => 'sometimes|required|exists:flocks,id',
            'mortality_count' => 'sometimes|integer|min:0',
            'average_weight' => 'sometimes|integer|min:0',
            'bird_count' => 'sometimes|integer|min:0',
            'mortality_percentage' => 'sometimes|numeric|min:0',
            'notes' => 'sometimes|nullable|string',
            'date' => 'sometimes|date',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        
        $updateData = $request->all();
        
        // If flock_id is being updated, get the new poultry_type_id
        if ($request->has('flock_id')) {
            $flock = \App\Models\Flock::findOrFail($request->flock_id);
            $updateData['poultry_type_id'] = $flock->poultry_type_id;
        }
        
        $report->update($updateData);
        if ($flock) {
            $flock->reconcileHouseAllocations();
        }
        $report->load(['flock', 'poultryType', 'creator']);
        return $this->sendResponse($report, 'Flock mortality report updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryMortalityReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete flock mortality reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete flock mortality reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock mortality report not found in this farm');
        }

        $flock = \App\Models\Flock::find($report->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $report->delete();
        if ($flock) {
            $flock->reconcileHouseAllocations();
        }
        return $this->sendResponse(null, 'Flock mortality report deleted successfully');
    }

    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock mortality reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock mortality reports');
        }
        $query = PoultryMortalityReport::where('farm_id', $farm->id);
        $statistics = [
            'total_mortality_reports' => $query->count(),
            'total_mortality' => $query->sum('mortality_count'),
        ];
        return $this->sendResponse($statistics, 'Flock mortality report statistics retrieved successfully');
    }

    /**
     * Get the total mortality count for a specific flock on a specific date.
     */
    public function getMortalityByFlockAndDate(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock mortality reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock mortality reports');
        }

        $validator = Validator::make($request->all(), [
            'flock_id' => 'required|exists:flocks,id',
            'date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $reports = PoultryMortalityReport::where('farm_id', $farm->id)
            ->where('flock_id', $request->flock_id)
            ->whereDate('date', $request->date)
            ->get();

        $totalMortality = $reports->sum('mortality_count');
        $reportCount = $reports->count();

        return $this->sendResponse([
            'total_mortality' => $totalMortality,
            'report_count' => $reportCount,
        ], 'Mortality data retrieved successfully');
    }
} 