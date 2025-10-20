<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\FlockDailyRecord;
use App\Models\BatchSchedule;
use App\Models\FeedingBatchSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FlockDailyRecordController extends ApiController
{
    public function index(Request $request, $farmId)
    {
        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|exists:farms,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Invalid farm ID', $validator->errors()->toArray());
        }

        $query = FlockDailyRecord::where('farm_id', $farmId);
        
        if ($request->has('flock_id')) {
            $query->where('flock_id', $request->flock_id);
        }
        
        if ($request->has('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        
        if ($request->has('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }
        
        $records = $query->with(['flock'])
            ->orderBy('date', 'desc')
            ->paginate($request->get('per_page', 15));
            
        return $this->sendResponse($records, 'Flock daily records retrieved successfully');
    }

    public function store(Request $request, $farmId)
    {
        $validator = Validator::make($request->all(), [
            'flock_id' => 'required|exists:flocks,id',
            'date' => 'required|date',
            'mortality_count' => 'nullable|integer|min:0',
            'culling_count' => 'nullable|integer|min:0',
            'average_weight_kg' => 'nullable|numeric|min:0',
            'feed_consumption_kg' => 'nullable|numeric|min:0',
            'water_consumption_liters' => 'nullable|numeric|min:0',
            'egg_production_count' => 'nullable|integer|min:0',
            'egg_weight_grams' => 'nullable|numeric|min:0',
            'temperature_celsius' => 'nullable|numeric',
            'humidity_percentage' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'additional_data' => 'nullable|array',
        ]);
        
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        
        // Verify that the flock belongs to the farm
        $flock = \App\Models\Flock::where('id', $request->flock_id)
            ->where('farm_id', $farmId)
            ->first();
            
        if (!$flock) {
            return $this->sendError('Flock not found in this farm', [], 404);
        }
        

        $recordData = $request->all(); 
        // return $this->sendError('Flock not found in this farm', $recordData, 404);
        $recordData['farm_id'] = $farmId;
        $recordData['recorded_by'] = auth()->user()->id;
        $record = FlockDailyRecord::create($recordData);
        $this->updateBatchSchedules($request->flock_id, $request->date);
        $this->updateFeedingBatchSchedules($request->flock_id, $request->date);
        
        return $this->sendResponse($record, 'Flock daily record created successfully', 201);
    }

    public function show($farmId, $id)
    {
        $record = FlockDailyRecord::where('id', $id)
            ->where('farm_id', $farmId)
            ->with(['flock'])
            ->first();
            
        if (!$record) {
            return $this->sendError('Flock daily record not found in this farm', [], 404);
        }
        
        return $this->sendResponse($record, 'Flock daily record retrieved successfully');
    }

    public function update(Request $request, $farmId, $id)
    {
        $record = FlockDailyRecord::where('id', $id)
            ->where('farm_id', $farmId)
            ->first();
            
        if (!$record) {
            return $this->sendError('Flock daily record not found in this farm', [], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'date' => 'sometimes|required|date',
            'age_days' => 'sometimes|required|integer|min:0',
            'total_birds' => 'sometimes|required|integer|min:0',
            'mortality_count' => 'nullable|integer|min:0',
            'culling_count' => 'nullable|integer|min:0',
            'average_weight_kg' => 'nullable|numeric|min:0',
            'feed_consumption_kg' => 'nullable|numeric|min:0',
            'water_consumption_liters' => 'nullable|numeric|min:0',
            'egg_production_count' => 'nullable|integer|min:0',
            'egg_weight_grams' => 'nullable|numeric|min:0',
            'temperature_celsius' => 'nullable|numeric',
            'humidity_percentage' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'additional_data' => 'nullable|array',
        ]);
        
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        
        $record->update($request->all());
        
        if ($request->has('flock_id') && $request->has('date')) {
            $this->updateBatchSchedules($request->flock_id, $request->date);
            $this->updateFeedingBatchSchedules($request->flock_id, $request->date);
        }
        
        return $this->sendResponse($record, 'Flock daily record updated successfully');
    }

    public function destroy($farmId, $id)
    {
        $record = FlockDailyRecord::where('id', $id)
            ->where('farm_id', $farmId)
            ->first();
            
        if (!$record) {
            return $this->sendError('Flock daily record not found in this farm', [], 404);
        }
        
        $record->delete();
        return $this->sendResponse(null, 'Flock daily record deleted successfully');
    }

    /**
     * Update related BatchSchedule(s) for the flock and date.
     */
    protected function updateBatchSchedules($flockId, $date)
    {
        $batchSchedules = BatchSchedule::where('flock_id', $flockId)->get();
        foreach ($batchSchedules as $batchSchedule) {
            // Custom update logic here, e.g., mark as completed for the date
            // $batchSchedule->update([...]);
        }
    }

    /**
     * Update related FeedingBatchSchedule(s) for the flock and date.
     */
    protected function updateFeedingBatchSchedules($flockId, $date)
    {
        $feedingBatchSchedules = FeedingBatchSchedule::where('flock_id', $flockId)->get();
        
        foreach ($feedingBatchSchedules as $feedingBatchSchedule) {
            // Custom update logic here, e.g., mark as completed for the date
            // $feedingBatchSchedule->update([...]);
        }
    }
} 