<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\FlockDailyRecord;
use App\Models\BatchSchedule;
use App\Models\FeedingBatchSchedule;
use App\Models\FeedingBatchScheduleItem;
use App\Models\FeedingScheduleItem;
use App\Models\Flock;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryMortalityReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

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
        $recordData['farm_id'] = $farmId;
        $recordData['recorded_by'] = auth()->user()->id;
        $record = FlockDailyRecord::create($recordData);
        $this->updateBatchSchedules($request->flock_id, $request->date);
        $this->updateFeedingBatchSchedules($request->flock_id, $request->date, $request->feed_consumption_kg, $record, $flock);
        $this->syncMortalityRecords($farmId, $flock, $request->date, $request->mortality_count ?? 0);
        
        return $this->sendResponse($record->fresh(), 'Flock daily record created successfully', 201);
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
     *
     * - If a batch schedule item already exists for the date → do nothing.
     * - If the date falls within the flock's arrival_date to expected_end_date range →
     *   calculate the feeding_day (adjusted for arrival_age_days), find the matching
     *   schedule item, compute actual_quantity from feed_consumption_kg, and create
     *   a batch schedule item.
     * - If the date is outside the flock's range → add a note to the daily record.
     *
     * feeding_day formula:
     *   days_since_arrival = (record_date - arrival_date) in days  (0 on arrival day)
     *   feeding_day = arrival_age_days + days_since_arrival + 1
     *
     * Example: arrival_age_days=7, arrival_date=Jan 1, record for Jan 1
     *   → days_since_arrival=0, feeding_day=7+0+1=8  (matches schedule item for day 8)
     */
    protected function updateFeedingBatchSchedules($flockId, $date, $feedConsumptionKg, $record, $flock)
    {
        // Find the feeding batch schedule for this flock (with schedule + items eager loaded)
        $feedingBatchSchedule = FeedingBatchSchedule::where('flock_id', $flockId)
            ->with(['schedule.items'])
            ->first();

        if (!$feedingBatchSchedule || !$feedingBatchSchedule->schedule) {
            return; // No batch schedule or no linked schedule for this flock
        }

        // Check if a batch schedule item already exists for this date
        $existingItem = FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $feedingBatchSchedule->id)
            ->where('feeding_date', $date)
            ->first();

        if ($existingItem) {
            return; // Item already exists for this date, do nothing
        }

        // Use the flock's own dates for the range check (unique per flock)
        $arrivalDate = Carbon::parse($flock->arrival_date);
        $expectedEndDate = $flock->expected_end_date ? Carbon::parse($flock->expected_end_date) : null;
        $recordDate = Carbon::parse($date);
        $arrivalAgeDays = $flock->arrival_age_days ?? 0;

        $isInRange = $recordDate->gte($arrivalDate) && (!$expectedEndDate || $recordDate->lte($expectedEndDate));

        if (!$isInRange) {
            // Date is outside the flock's range — append a note to the daily record
            $schedule = $feedingBatchSchedule->schedule;
            $rangeEnd = $expectedEndDate ? $expectedEndDate->toDateString() : 'open-ended';
            $note = "Note: The date {$date} does not fall within the feeding schedule \"{$schedule->title}\" range for this flock ({$arrivalDate->toDateString()} to {$rangeEnd}).";
            $existingNotes = $record->notes;
            $record->update([
                'notes' => $existingNotes ? $existingNotes . "\n" . $note : $note,
            ]);
            return;
        }

        // Date is in range — determine which feeding_day this corresponds to
        // Account for arrival_age_days: birds already had X days before arriving
        $daysSinceArrival = $arrivalDate->diffInDays($recordDate); // 0 on arrival day
        $feedingDay = $arrivalAgeDays + $daysSinceArrival + 1;

        // Find the schedule item that matches this feeding day
        $schedule = $feedingBatchSchedule->schedule;
        $scheduleItem = $schedule->items->where('feeding_day', $feedingDay)->first();

        if (!$scheduleItem) {
            return; // No schedule item defined for this feeding day
        }

        // Calculate actual_quantity (per bird in grams) from the daily record's feed_consumption_kg
        // Formula: actual_quantity = (feed_consumption_kg * 1000) / flock_quantity
        // Falls back to the scheduled quantity if no feed consumption was recorded
        $flockQuantity = $flock->quantity ?? 1;

        if ($feedConsumptionKg && $feedConsumptionKg > 0 && $flockQuantity > 0) {
            $actualQuantity = ($feedConsumptionKg * 1000) / $flockQuantity;
        } else {
            $actualQuantity = $scheduleItem->quantity; // Use the planned quantity as fallback
        }

        // Create the batch schedule item
        FeedingBatchScheduleItem::create([
            'feeding_batch_schedule_id' => $feedingBatchSchedule->id,
            'feeding_schedule_item_id' => $scheduleItem->id,
            'actual_feeding_time' => $scheduleItem->feeding_times,
            'actual_quantity' => round($actualQuantity, 2),
            'feeding_date' => $date,
            'status' => 'completed',
        ]);

        // Update feed inventory if feed was consumed
        $farmId = $flock->farm_id;
        $feedTypeId = $scheduleItem->feed_type_id;
        if ($feedConsumptionKg && $feedConsumptionKg > 0 && $feedTypeId && $farmId) {
            // Find an available inventory for this feed type (FIFO - oldest first)
            $inventory = PoultryFeedInventory::where('farm_id', $farmId)
                ->where('poultry_feed_type_id', $feedTypeId)
                ->where('quantity', '>', 0)
                ->whereIn('status', ['available', 'in_use'])
                ->orderBy('created_at', 'asc')
                ->first();

            if ($inventory) {
                $deductAmount = min($feedConsumptionKg, $inventory->quantity);
                $inventory->decrement('quantity', $deductAmount);
                $inventory->refresh();
                $inventory->updateStatusBasedOnQuantity();

                // Log the feed usage
                PoultryFeedUsage::create([
                    'farm_id' => $farmId,
                    'poultry_feed_inventory_id' => $inventory->id,
                    'poultry_feed_type_id' => $feedTypeId,
                    'flock_id' => $flock->id,
                    'quantity' => $deductAmount,
                    'unit_cost' => $inventory->unit_cost ?? 0,
                    'usage_date' => $date,
                    'created_by' => auth()->id(),
                ]);
            }
        }
    }

    /**
     * Sync mortality records for the flock on the given date.
     *
     * - If the request mortality equals the existing total → do nothing.
     * - If the request mortality is 0 → delete all records for that date.
     * - If the request mortality is lower → reduce an existing record by the difference.
     *   If that record's count reaches 0, delete it.
     * - If the request mortality is higher → create a new record for the difference.
     */
    protected function syncMortalityRecords($farmId, $flock, $date, $requestedMortality)
    {
        $requestedMortality = (int) $requestedMortality;

        // Get all existing mortality records for this flock + date
        $existingRecords = PoultryMortalityReport::where('farm_id', $farmId)
            ->where('flock_id', $flock->id)
            ->whereDate('date', $date)
            ->get();

        $existingTotal = (int) $existingRecords->sum('mortality_count');

        // Equal → nothing to do
        if ($requestedMortality === $existingTotal) {
            return;
        }

        // Requested is 0 → delete all records for this date
        if ($requestedMortality === 0) {
            PoultryMortalityReport::where('farm_id', $farmId)
                ->where('flock_id', $flock->id)
                ->whereDate('date', $date)
                ->delete();
            return;
        }

        // Requested is lower → reduce existing records
        if ($requestedMortality < $existingTotal) {
            $difference = $existingTotal - $requestedMortality;

            // Walk through records and subtract the difference
            foreach ($existingRecords as $report) {
                if ($difference <= 0) break;

                if ($report->mortality_count <= $difference) {
                    // This record is fully consumed by the reduction → delete it
                    $difference -= $report->mortality_count;
                    $report->delete();
                } else {
                    // Partially reduce this record
                    $newCount = $report->mortality_count - $difference;
                    $birdCount = $flock->quantity ?? 1;
                    $report->update([
                        'mortality_count' => $newCount,
                        'mortality_percentage' => $birdCount > 0 ? round(($newCount / $birdCount) * 100, 2) : 0,
                    ]);
                    $difference = 0;
                }
            }
            return;
        }

        // Requested is higher → create a new record for the difference
        $difference = $requestedMortality - $existingTotal;
        $birdCount = $flock->quantity ?? 1;

        PoultryMortalityReport::create([
            'flock_id' => $flock->id,
            'farm_id' => $farmId,
            'poultry_type_id' => $flock->poultry_type_id,
            'date' => $date,
            'mortality_count' => $difference,
            'bird_count' => $birdCount,
            'mortality_percentage' => $birdCount > 0 ? round(($difference / $birdCount) * 100, 2) : 0,
            'notes' => 'Auto-created from daily record entry.',
            'recorded_by' => auth()->user()->id,
        ]);
    }
} 