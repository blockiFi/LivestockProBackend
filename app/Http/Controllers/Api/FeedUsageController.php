<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\FeedingBatchSchedule;
use App\Models\FeedingBatchScheduleItem;
use App\Models\Flock;
use App\Models\PoultryFeedUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class FeedUsageController extends ApiController
{
    public function index(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed usages');
        }
        $query = PoultryFeedUsage::where('farm_id', $farm->id);
        if ($request->has('feed_inventory_id')) {
            $query->where('poultry_feed_inventory_id', $request->feed_inventory_id);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('usage_date', 'like', "%{$search}%");
        }
        $sortField = $request->input('sort_by', 'usage_date');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);
        $perPage = $request->input('per_page', 10);
        $usages = $query->paginate($perPage);
        return $this->sendResponse($usages, 'Feed usages retrieved successfully');
    }

    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create feed usages');
        }
        $validator = Validator::make($request->all(), [
            'poultry_feed_inventory_id' => 'required|exists:poultry_feed_inventories,id',
            'poultry_feed_type_id' => 'required|exists:poultry_feed_types,id',
            'flock_id' => 'required|exists:flocks,id',
            'quantity' => 'required|numeric|min:0',
            'unit_cost' => 'required|numeric|min:0',
            'usage_date' => 'required|date',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        // Get the feed inventory record first to check availability
        $feedInventory = \App\Models\PoultryFeedInventory::findOrFail($request->poultry_feed_inventory_id);
        
        // Check if there's enough inventory
        if ($feedInventory->quantity < $request->quantity) {
            return $this->sendError('Insufficient inventory quantity. Available: ' . $feedInventory->quantity . ', Requested: ' . $request->quantity, [], 400);
        }

        // Use database transaction to ensure atomicity
        $usage = DB::transaction(function () use ($request, $farm, $feedInventory) {
            // Reduce inventory quantity
            $feedInventory->decrement('quantity', $request->quantity);
            
            // Update inventory status based on new quantity
            $feedInventory->refresh();
            $feedInventory->updateStatusBasedOnQuantity();
            
            // Create the usage record
            $usage = PoultryFeedUsage::create(array_merge($request->all(), [
                'farm_id' => $farm->id,
                'created_by' => auth()->id()
            ]));

            // Auto-sync feeding batch schedule item for this flock
            $this->syncBatchScheduleItem(
                $request->flock_id,
                $request->poultry_feed_type_id,
                $request->quantity,
                $request->usage_date
            );

            return $usage;
        });

        return $this->sendResponse($usage, 'Feed usage created successfully', 201);
    }

    public function show(Request $request, $farm, PoultryFeedUsage $usage)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed usages');
        }
        if ($usage->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed usage not found in this farm');
        }
        return $this->sendResponse($usage, 'Feed usage retrieved successfully');
    }

    public function update(Request $request, $farm, PoultryFeedUsage $usage)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update feed usages');
        }
        if ($usage->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed usage not found in this farm');
        }
        $validator = Validator::make($request->all(), [
            'poultry_feed_inventory_id' => 'sometimes|required|exists:poultry_feed_inventories,id',
            'poultry_feed_type_id' => 'sometimes|required|exists:poultry_feed_types,id',
            'flock_id' => 'sometimes|required|exists:flocks,id',
            'quantity' => 'sometimes|numeric|min:0',
            'unit_cost' => 'sometimes|numeric|min:0',
            'usage_date' => 'sometimes|date',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        // Handle inventory adjustments if quantity is being updated
        if ($request->has('quantity') && $request->quantity != $usage->quantity) {
            DB::transaction(function () use ($request, $usage) {
                $feedInventory = $usage->feedInventory;
                
                if ($feedInventory) {
                    $quantityDifference = $request->quantity - $usage->quantity;
                    
                    // Adjust inventory based on quantity difference
                    if ($quantityDifference > 0) {
                        // Quantity increased - reduce inventory
                        $feedInventory->decrement('quantity', $quantityDifference);
                    } elseif ($quantityDifference < 0) {
                        // Quantity decreased - return to inventory
                        $feedInventory->increment('quantity', abs($quantityDifference));
                    }
                    
                    // Update inventory status based on new quantity
                    $feedInventory->refresh();
                    $feedInventory->updateStatusBasedOnQuantity();
                }
                
                // Update the usage record
                $usage->update($request->all());
            });
        } else {
            // No quantity change, just update normally
            $usage->update($request->all());
        }

        return $this->sendResponse($usage, 'Feed usage updated successfully');
    }

    public function destroy(Request $request, $farm, PoultryFeedUsage $usage)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete feed usages');
        }
        if ($usage->farm_id !== $farm->id) {
            return $this->sendNotFoundError('Feed usage not found in this farm');
        }

        // Use database transaction to ensure atomicity
        \DB::transaction(function () use ($usage) {
            // Get the feed inventory record
            $feedInventory = $usage->feedInventory;
            
            if ($feedInventory) {
                // Return the quantity to the inventory
                $feedInventory->increment('quantity', $usage->quantity);
                
                // Update inventory status based on new quantity
                $feedInventory->refresh();
                $feedInventory->updateStatusBasedOnQuantity();
            }
            
            // Delete the usage record
            $usage->delete();
        });

        return $this->sendResponse(null, 'Feed usage deleted successfully');
    }

    public function statistics(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view feed usages', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view feed usages');
        }
        $query = PoultryFeedUsage::where('farm_id', $farm->id);
        $statistics = [
            'total_feed_usages' => $query->count(),
            'total_quantity' => $query->sum('quantity'),
            'by_feed_type' => $query->selectRaw('poultry_feed_type_id, sum(quantity) as total_quantity')->groupBy('poultry_feed_type_id')->get(),
        ];
        return $this->sendResponse($statistics, 'Feed usage statistics retrieved successfully');
    }

    /**
     * Auto-create or update a FeedingBatchScheduleItem when feed usage is recorded.
     *
     * Determines the feeding day from the flock's arrival date and age offset,
     * finds the matching schedule item, then creates or updates the batch item.
     */
    protected function syncBatchScheduleItem($flockId, $feedTypeId, $quantity, $usageDate)
    {
        if (!$flockId || !$feedTypeId) {
            return;
        }

        $flock = Flock::find($flockId);
        if (!$flock) {
            return;
        }

        // Find the feeding batch schedule for this flock
        $batchSchedule = FeedingBatchSchedule::where('flock_id', $flockId)->first();
        if (!$batchSchedule) {
            return;
        }

        $schedule = $batchSchedule->schedule;
        if (!$schedule || !$schedule->items) {
            return;
        }

        // Determine the feeding day from flock dates
        $arrivalDate = Carbon::parse($flock->arrival_date);
        $recordDate = Carbon::parse($usageDate);
        $arrivalAgeDays = $flock->arrival_age_days ?? 0;
        $daysSinceArrival = $arrivalDate->diffInDays($recordDate);
        $feedingDay = $arrivalAgeDays + $daysSinceArrival + 1;

        // Find the schedule item matching this feed type and feeding day
        $scheduleItem = $schedule->items
            ->where('feed_type_id', $feedTypeId)
            ->where('feeding_day', $feedingDay)
            ->first();

        // If no exact day match, try just the feed type (for flexible schedules)
        if (!$scheduleItem) {
            $scheduleItem = $schedule->items
                ->where('feed_type_id', $feedTypeId)
                ->first();
        }

        if (!$scheduleItem) {
            return;
        }

        // Calculate per-bird quantity (grams) from total usage (kg)
        $flockQuantity = $flock->actual_quantity ?? $flock->quantity ?? 1;
        $perBirdQuantity = $flockQuantity > 0 ? ($quantity * 1000) / $flockQuantity : $quantity;

        // Check if a batch schedule item already exists for this date
        $existingItem = FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batchSchedule->id)
            ->whereDate('feeding_date', $usageDate)
            ->where('feeding_schedule_item_id', $scheduleItem->id)
            ->first();

        if ($existingItem) {
            // Update the existing item with the new actual quantity
            $existingItem->update([
                'actual_quantity' => round($perBirdQuantity, 2),
                'status' => 'completed',
            ]);
        } else {
            // Create a new batch schedule item
            FeedingBatchScheduleItem::create([
                'feeding_batch_schedule_id' => $batchSchedule->id,
                'feeding_schedule_item_id' => $scheduleItem->id,
                'actual_feeding_time' => $scheduleItem->feeding_times,
                'actual_quantity' => round($perBirdQuantity, 2),
                'feeding_date' => $usageDate,
                'status' => 'completed',
            ]);
        }
    }
} 