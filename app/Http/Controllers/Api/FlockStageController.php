<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlockStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Traits\RegisterEvents;
use Spatie\Permission\Models\PermissionRegistrar;

class FlockStageController extends ApiController
{
    use RegisterEvents;

    /**
     * Display a listing of flock stages.
     */
    public function index(Request $request , $pagination = null)
    {
        if (!auth()->user()->can('view flock stages', 'api')) {
            return $this->sendError('You do not have permission to view flock stages', [], 403);
        }

        $query = FlockStage::query();

        // Apply search filter if provided
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // // Apply status filter if provided
        // if ($request->has('status')) {
        //     $query->where('status', $request->status);
        // }

        if ($pagination) {
            $flockStages = $query->paginate(10);
        } else {
            $flockStages = $query->get();
        }

        return $this->sendResponse($flockStages, 'Flock stages retrieved successfully');
    }

    /**
     * Store a newly created flock stage.
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('manage flock stages', 'api')) {
            return $this->sendError('You do not have permission to manage flock stages', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:flock_stages,name',
            'description' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        try {
            DB::beginTransaction();

            $flockStage = FlockStage::create([
                'name' => $request->name,
                'description' => $request->description,
                'created_by' => auth()->id()
            ]);

            $this->RegisterEvent(
                eventType: 'flock_stage_created',
                tableName: 'flock_stages',
                tableId: $flockStage->id
            );

            DB::commit();

            return $this->sendResponse($flockStage, 'Flock stage created successfully', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error creating flock stage', [$e->getMessage()], 500);
        }
    }

    /**
     * Display the specified flock stage.
     */
    public function show($id)
    {
        if (!auth()->user()->can('view flock stages', 'api')) {
            return $this->sendError('You do not have permission to view flock stages', [], 403);
        }

        $flockStage = FlockStage::findOrFail($id);

        return $this->sendResponse($flockStage, 'Flock stage retrieved successfully');
    }

    /**
     * Update the specified flock stage.
     */
    public function update(Request $request, $id)
    {
        if (!auth()->user()->can('manage flock stages', 'api')) {
            return $this->sendError('You do not have permission to manage flock stages', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:flock_stages,name,' . $id,
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        try {
            DB::beginTransaction();

            $flockStage = FlockStage::findOrFail($id);
            $flockStage->update([
                'name' => $request->name,
                'description' => $request->description,
                'status' => $request->status
            ]);

            $this->RegisterEvent(
                eventType: 'flock_stage_updated',
                tableName: 'flock_stages',
                tableId: $flockStage->id
            );

            DB::commit();

            return $this->sendResponse($flockStage, 'Flock stage updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error updating flock stage', [$e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified flock stage.
     */
    public function destroy($id)
    {
        if (!auth()->user()->can('manage flock stages', 'api')) {
            return $this->sendError('You do not have permission to manage flock stages', [], 403);
        }

        try {
            DB::beginTransaction();

            $flockStage = FlockStage::findOrFail($id);

            // Check if the flock stage is being used by any flocks
            if ($flockStage->flocks()->exists()) {
                return $this->sendError('Cannot delete flock stage that is being used by flocks', [], 422);
            }

            $this->RegisterEvent(
                eventType: 'flock_stage_deleted',
                tableName: 'flock_stages',
                tableId: $flockStage->id
            );

            $flockStage->delete();

            DB::commit();

            return $this->sendResponse(null, 'Flock stage deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error deleting flock stage', [$e->getMessage()], 500);
        }
    }

    /**
     * Get statistics for a flock stage.
     */
    public function getStatistics($id)
    {
        if (!auth()->user()->can('view flock stages', 'api')) {
            return $this->sendError('You do not have permission to view flock stages', [], 403);
        }

        $flockStage = FlockStage::findOrFail($id);

        $statistics = [
            'total_flocks' => $flockStage->flocks()->count(),
            'active_flocks' => $flockStage->flocks()->where('status', 'active')->count(),
            'completed_flocks' => $flockStage->flocks()->where('status', 'completed')->count()
        ];

        return $this->sendResponse($statistics, 'Flock stage statistics retrieved successfully');
    }
}
