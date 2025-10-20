<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\PoultryMedication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PoultryMedicationController extends ApiController
{
    /**
     * Display a listing of poultry medications.
     */
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view medications', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view medications');
        }
        $query = PoultryMedication::where(function($q) use ($farm) {
            $q->where('farm_id', $farm->id)
              ->orWhereNull('farm_id');
        });
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }
        $sortField = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortField, $sortDirection);
        $perPage = $request->input('per_page', 10);
        $medications = $query->paginate($perPage);
        return $this->sendResponse($medications, 'Poultry medications retrieved successfully');
    }

    /**
     * Store a newly created poultry medication.
     */
    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create medications', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create medications');
        }
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:poultry_medications,name',
            'description' => 'nullable|string',
           
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $medication = PoultryMedication::create(array_merge($request->all(), [
            'farm_id' => $farm->id,
            'type' => 'user',
        ]));
        return $this->sendResponse($medication, 'Poultry medication created successfully', 201);
    }

    /**
     * Display the specified poultry medication.
     */
    public function show(Request $request, $farm, PoultryMedication $medication)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view medications', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view medications');
        }
        if ($medication->farm_id !== null && $medication->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Medication not found in this farm');
        }
        return $this->sendResponse($medication, 'Poultry medication retrieved successfully');
    }

    /**
     * Update the specified poultry medication.
     */
    public function update(Request $request, $farm, PoultryMedication $medication)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update medications', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update medications');
        }
        if ($medication->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Medication not found in this farm');
        }
        if ($medication->type === 'default' && $medication->farm_id === null) {
            return $this->sendError('Cannot update default medications', [], 403);
        }
        $validator = Validator::make($request->only(['name', 'description']), [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('poultry_medications')->ignore($medication->id)],
            'description' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
      
        $medication->update($request->only(['name', 'description']));
        return $this->sendResponse($medication, 'Poultry medication updated successfully');
    }

    /**
     * Remove the specified poultry medication.
     */
    public function destroy(Request $request, $farm, PoultryMedication $medication)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete medications', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete medications');
        }
        if ($medication->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Medication not found in this farm');
        }
        if ($medication->type === 'default' && $medication->farm_id === null) {
            return $this->sendError('Cannot delete default medications', [], 403);
        }
        $medication->delete();
        return $this->sendResponse(null, 'Poultry medication deleted successfully');
    }

    /**
     * Get medication statistics.
     */
    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view medications', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view medications');
        }
        $query = PoultryMedication::where(function($q) use ($farm) {
            $q->where('farm_id', $farm->id)
              ->orWhereNull('farm_id');
        });
        $statistics = [
            'total_medications' => $query->count(),
            'by_type' => $query->selectRaw('type, count(*) as count')
                             ->groupBy('type')
                             ->get(),
        ];
        return $this->sendResponse($statistics, 'Poultry medication statistics retrieved successfully');
    }
} 