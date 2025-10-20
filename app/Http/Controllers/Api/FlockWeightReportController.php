<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\PoultryFlockWeightReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FlockWeightReportController extends ApiController
{
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock weight reports');
        }
        $query = PoultryFlockWeightReport::where('farm_id', $farm->id);
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
        return $this->sendResponse($reports, 'Flock weight reports retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create flock weight reports');
        }
        $validator = Validator::make($request->all(), [
            'flock_id' => 'required|exists:flocks,id',
            'report_date' => 'required|date',
            'average_weight' => 'required|numeric|min:0',
            'min_weight' => 'required|numeric|min:0',
            'max_weight' => 'required|numeric|min:0',
            'number_of_birds' => 'required|integer|min:0',
            'sample_size' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->all());
        }
        $report = PoultryFlockWeightReport::create(array_merge($request->all(), [
            'farm_id' => $farm->id,
            'recorded_by' => $user->id,
        ]));
        return $this->sendResponse($report, 'Flock weight report created successfully', 201);
    }

    public function show(Request $request, $farm, PoultryFlockWeightReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock weight reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock weight report not found in this farm');
        }
        return $this->sendResponse($report, 'Flock weight report retrieved successfully');
    }

    public function update(Request $request, $farm, PoultryFlockWeightReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update flock weight reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock weight report not found in this farm');
        }
        $validator = Validator::make($request->all(), [
            'flock_id' => 'sometimes|required|exists:flocks,id',
            'record_date' => 'sometimes|date',
            'average_weight_kg' => 'sometimes|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->all());
        }
        $report->update($request->all());
        return $this->sendResponse($report, 'Flock weight report updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryFlockWeightReport $report)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete flock weight reports');
        }
        if ($report->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Flock weight report not found in this farm');
        }
        $report->delete();
        return $this->sendResponse(null, 'Flock weight report deleted successfully');
    }

    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view flock weight reports', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock weight reports');
        }
        $query = PoultryFlockWeightReport::where('farm_id', $farm->id);
        $statistics = [
            'total_weight_reports' => $query->count(),
            'average_weight' => $query->avg('average_weight_kg'),
        ];
        return $this->sendResponse($statistics, 'Flock weight report statistics retrieved successfully');
    }
} 