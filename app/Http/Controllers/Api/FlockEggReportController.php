<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\PoultryFlockEggReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FlockEggReportController extends ApiController
{
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock egg reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock egg reports');
        }
        $query = PoultryFlockEggReport::where('farm_id', $farm->id);
        if ($request->has('flock_id')) {
            $query->where('flock_id', $request->flock_id);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('record_date', 'like', "%{$search}%");
        }
        $sortField = $request->input('sort_by', 'record_date');
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
        if (!$user->hasPermissionTo('create flock egg reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create flock egg reports');
        }
        $validator = Validator::make($request->all(), [
            'flock_id' => 'required|exists:flocks,id',
            'record_date' => 'required|date',
            'egg_production_count' => 'required|numeric|min:0',
            'egg_weight_grams' => 'nullable|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $flock = Flock::findOrFail($request->flock_id);
        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        $report = PoultryFlockEggReport::create(array_merge($request->all(), [
            'farm_id' => $farm->id
        ]));
        return $this->sendResponse($report, 'Flock egg report created successfully', 201);
    }

    public function show(Request $request, $farm, PoultryFlockEggReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock egg reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock egg reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock egg report not found in this farm');
        }
        return $this->sendResponse($report, 'Flock egg report retrieved successfully');
    }

    public function update(Request $request, $farm, PoultryFlockEggReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update flock egg reports', 'api', $farm)) {
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
            'record_date' => 'sometimes|date',
            'egg_production_count' => 'sometimes|numeric|min:0',
            'egg_weight_grams' => 'nullable|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $report->update($request->all());
        return $this->sendResponse($report, 'Flock egg report updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryFlockEggReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete flock egg reports', 'api', $farm)) {
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
        if (!$user->hasPermissionTo('view flock egg reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock egg reports');
        }
        $query = PoultryFlockEggReport::where('farm_id', $farm->id);
        $statistics = [
            'total_egg_reports' => $query->count(),
            'total_eggs' => $query->sum('egg_production_count'),
            'average_egg_weight' => $query->avg('egg_weight_grams'),
        ];
        return $this->sendResponse($statistics, 'Flock egg report statistics retrieved successfully');
    }
} 