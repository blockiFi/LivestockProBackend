<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\PoultryFlockEggReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FlockEggReportController extends ApiController
{
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (! $user->hasPermissionTo('view flock egg reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock egg reports');
        }

        $query = PoultryFlockEggReport::with('recordedBy:id,name')
            ->where('farm_id', $farm->id);

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

        return $this->sendResponse($reports, 'Flock egg reports retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (! $user->hasPermissionTo('create flock egg reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create flock egg reports');
        }

        $validator = Validator::make($request->all(), [
            'flock_id' => 'required|exists:flocks,id',
            'date' => 'required|date',
            'eggs_collected' => 'required|numeric|min:0',
            'eggs_broken' => 'nullable|integer|min:0',
            'average_egg_weight' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $eggsCollected = (int) $request->input('eggs_collected');
        $eggsBroken = (int) $request->input('eggs_broken', 0);
        if ($eggsBroken > $eggsCollected) {
            return $this->sendValidationError('Validation failed', [
                'eggs_broken' => ['Broken eggs cannot exceed eggs collected.'],
            ]);
        }

        $flock = Flock::findOrFail($request->flock_id);
        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        if ($flock->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock not found in this farm');
        }

        $date = $request->input('date');
        $birdCount = $flock->birdCountOnDate($date);
        $productionPercentage = $birdCount > 0
            ? round(($eggsCollected / $birdCount) * 100, 2)
            : 0;

        $report = PoultryFlockEggReport::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'date' => $date,
            'eggs_collected' => $eggsCollected,
            'eggs_broken' => $eggsBroken,
            'average_egg_weight' => $request->input('average_egg_weight', 0),
            'production_percentage' => $productionPercentage,
            'bird_count' => $birdCount,
            'notes' => $request->input('notes'),
            'recorded_by' => $user->id,
        ]);

        $report->load('recordedBy:id,name');

        return $this->sendResponse($report, 'Flock egg report created successfully', 201);
    }

    public function show(Request $request, $farm, PoultryFlockEggReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (! $user->hasPermissionTo('view flock egg reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock egg reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock egg report not found in this farm');
        }

        $report->load('recordedBy:id,name');

        return $this->sendResponse($report, 'Flock egg report retrieved successfully');
    }

    public function update(Request $request, $farm, PoultryFlockEggReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (! $user->hasPermissionTo('update flock egg reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update flock egg reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock egg report not found in this farm');
        }

        $flock = Flock::find($report->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'flock_id' => 'sometimes|required|exists:flocks,id',
            'date' => 'sometimes|date',
            'eggs_collected' => 'sometimes|numeric|min:0',
            'eggs_broken' => 'nullable|integer|min:0',
            'average_egg_weight' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $flock = Flock::findOrFail($request->input('flock_id', $report->flock_id));
        $date = $request->input('date', $report->date?->toDateString() ?? $report->date);
        $eggsCollected = (int) $request->input('eggs_collected', $report->eggs_collected);
        $eggsBroken = (int) $request->input('eggs_broken', $report->eggs_broken ?? 0);
        if ($eggsBroken > $eggsCollected) {
            return $this->sendValidationError('Validation failed', [
                'eggs_broken' => ['Broken eggs cannot exceed eggs collected.'],
            ]);
        }

        $birdCount = $flock->birdCountOnDate($date);
        $productionPercentage = $birdCount > 0
            ? round(($eggsCollected / $birdCount) * 100, 2)
            : 0;

        $report->update([
            'flock_id' => $flock->id,
            'date' => $date,
            'eggs_collected' => $eggsCollected,
            'eggs_broken' => $eggsBroken,
            'average_egg_weight' => $request->has('average_egg_weight')
                ? $request->input('average_egg_weight')
                : $report->average_egg_weight,
            'production_percentage' => $productionPercentage,
            'bird_count' => $birdCount,
            'notes' => $request->has('notes') ? $request->input('notes') : $report->notes,
            'recorded_by' => $user->id,
        ]);

        $report->load('recordedBy:id,name');

        return $this->sendResponse($report->fresh(), 'Flock egg report updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryFlockEggReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (! $user->hasPermissionTo('delete flock egg reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete flock egg reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock egg report not found in this farm');
        }

        $flock = Flock::find($report->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $report->delete();

        return $this->sendResponse(null, 'Flock egg report deleted successfully');
    }

    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (! $user->hasPermissionTo('view flock egg reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock egg reports');
        }

        $query = PoultryFlockEggReport::where('farm_id', $farm->id);

        if ($request->has('flock_id')) {
            $query->where('flock_id', $request->flock_id);
        }

        $statistics = [
            'total_egg_reports' => (clone $query)->count(),
            'total_eggs' => (clone $query)->sum('eggs_collected'),
            'total_eggs_broken' => (clone $query)->sum('eggs_broken'),
            'average_egg_weight' => (clone $query)->avg('average_egg_weight'),
        ];

        return $this->sendResponse($statistics, 'Flock egg report statistics retrieved successfully');
    }
}
