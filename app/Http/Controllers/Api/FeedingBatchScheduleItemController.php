<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\FeedingBatchScheduleItem;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FeedingBatchScheduleItemController extends ApiController
{

    public function index()
    {
        $feedingBatchScheduleId = request('feeding_batch_schedule_id');
        $farmId = null;
        if ($feedingBatchScheduleId) {
            $feedingBatchSchedule = \App\Models\FeedingBatchSchedule::find($feedingBatchScheduleId);
            $flock = $feedingBatchSchedule ? $feedingBatchSchedule->flock : null;
            $farmId = $flock ? $flock->farm_id : null;
        }
        if ($farmId && !auth()->user()->can('view feeding batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding batch schedule items');
        }
        $items = FeedingBatchScheduleItem::with(['batchSchedule', 'scheduleItem'])->paginate(15);
        return $this->sendResponse($items, 'Feeding batch schedule items retrieved successfully');
    }

    public function store(Request $request)
    {
        $feedingBatchSchedule = \App\Models\FeedingBatchSchedule::find($request->feeding_batch_schedule_id);
        $flock = $feedingBatchSchedule ? $feedingBatchSchedule->flock : null;
        $farmId = $flock ? $flock->farm_id : null;
        if ($farmId && !auth()->user()->can('create feeding batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create feeding batch schedule items');
        }
        $validator = Validator::make($request->all(), [
            'feeding_batch_schedule_id' => 'required|exists:feeding_batch_schedules,id',
            'feeding_schedule_item_id' => 'required|exists:feeding_schedule_items,id',
            'actual_feeding_time' => 'nullable|array',
            'actual_feeding_time.*.time' => 'required_with:actual_feeding_time|string',
            'actual_feeding_time.*.percentage' => 'required_with:actual_feeding_time|numeric|min:0|max:100',
            'actual_quantity' => 'nullable|numeric|min:0',
            'feeding_date' => 'required|date',
            'status' => 'sometimes|in:scheduled,completed,missed,late',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $item = DB::transaction(function () use ($request, $farmId, $flock) {
            $item = FeedingBatchScheduleItem::create($request->only([
                'feeding_batch_schedule_id',
                'feeding_schedule_item_id',
                'actual_feeding_time',
                'actual_quantity',
                'feeding_date',
                'status',
            ]));

            // Update feed inventory if actual_quantity is provided and farm is known
            $quantityUsed = $request->actual_quantity;
            if ($quantityUsed && $quantityUsed > 0 && $farmId) {
                // Get the feed type from the schedule item
                $scheduleItem = \App\Models\FeedingScheduleItem::find($request->feeding_schedule_item_id);
                $feedTypeId = $scheduleItem ? $scheduleItem->feed_type_id : null;

                if ($feedTypeId) {
                    // Find an available inventory for this feed type and farm (FIFO - oldest first)
                    $inventory = PoultryFeedInventory::where('farm_id', $farmId)
                        ->where('poultry_feed_type_id', $feedTypeId)
                        ->where('quantity', '>', 0)
                        ->whereIn('status', ['available', 'in_use'])
                        ->orderBy('created_at', 'asc')
                        ->first();

                    if ($inventory) {
                        // Deduct from inventory (don't go below zero)
                        $deductAmount = min($quantityUsed, $inventory->quantity);
                        $inventory->decrement('quantity', $deductAmount);
                        $inventory->refresh();
                        $inventory->updateStatusBasedOnQuantity();

                        // Log the feed usage
                        PoultryFeedUsage::create([
                            'farm_id' => $farmId,
                            'poultry_feed_inventory_id' => $inventory->id,
                            'poultry_feed_type_id' => $feedTypeId,
                            'flock_id' => $flock ? $flock->id : null,
                            'quantity' => $deductAmount,
                            'unit_cost' => $inventory->unit_cost ?? 0,
                            'usage_date' => $request->feeding_date,
                            'created_by' => auth()->id(),
                        ]);

                        $item->inventory_note = $deductAmount < $quantityUsed
                            ? "Partial inventory deducted: {$deductAmount} of {$quantityUsed}. Insufficient stock."
                            : null;
                    } else {
                        $item->inventory_note = "No available inventory found for this feed type.";
                    }
                }
            }

            return $item;
        });

        return $this->sendResponse($item, 'Feeding batch schedule item created successfully', 201);
    }

    public function show($id)
    {
        $item = FeedingBatchScheduleItem::with(['batchSchedule', 'scheduleItem'])->findOrFail($id);
        $farmId = $item->batchSchedule && $item->batchSchedule->flock ? $item->batchSchedule->flock->farm_id : null;
        if ($farmId && !auth()->user()->can('view feeding batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this feeding batch schedule item');
        }
        return $this->sendResponse($item, 'Feeding batch schedule item retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $item = FeedingBatchScheduleItem::findOrFail($id);
        $farmId = $item->batchSchedule && $item->batchSchedule->flock ? $item->batchSchedule->flock->farm_id : null;
        if ($farmId && !auth()->user()->can('update feeding batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this feeding batch schedule item');
        }
        $validator = Validator::make($request->all(), [
            'feeding_batch_schedule_id' => 'sometimes|required|exists:feeding_batch_schedules,id',
            'feeding_schedule_item_id' => 'sometimes|required|exists:feeding_schedule_items,id',
            'actual_feeding_time' => 'nullable|array',
            'actual_feeding_time.*.time' => 'required_with:actual_feeding_time|string',
            'actual_feeding_time.*.percentage' => 'required_with:actual_feeding_time|numeric|min:0|max:100',
            'actual_quantity' => 'nullable|numeric|min:0',
            'feeding_date' => 'sometimes|required|date',
            'status' => 'sometimes|in:scheduled,completed,missed,late',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $item->update($request->only([
            'feeding_batch_schedule_id',
            'feeding_schedule_item_id',
            'actual_feeding_time',
            'actual_quantity',
            'feeding_date',
            'status',
        ]));
        return $this->sendResponse($item, 'Feeding batch schedule item updated successfully');
    }

    public function destroy($id)
    {
        $item = FeedingBatchScheduleItem::findOrFail($id);
        $farmId = $item->batchSchedule && $item->batchSchedule->flock ? $item->batchSchedule->flock->farm_id : null;
        if ($farmId && !auth()->user()->can('delete feeding batch schedule items', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this feeding batch schedule item');
        }
        $item->delete();
        return $this->sendResponse(null, 'Feeding batch schedule item deleted successfully');
    }

    /**
     * Get a feeding batch schedule item for a specific flock (batch) on a specific date.
     */
    public function getByBatchAndDate(Request $request, $farm, $flockId)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $flock = \App\Models\Flock::find($flockId);

        if (!$flock) {
            return $this->sendError('Flock not found', [], 404);
        }

        if (!auth()->user()->can('view feeding batch schedule items', 'api', $flock->farm_id)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding batch schedule items');
        }

        $batchSchedule = \App\Models\FeedingBatchSchedule::where('flock_id', $flockId)->first();

        if (!$batchSchedule) {
            return $this->sendResponse(null, 'No feeding batch schedule found for this flock');
        }

        $item = FeedingBatchScheduleItem::with(['batchSchedule', 'scheduleItem'])
            ->where('feeding_batch_schedule_id', $batchSchedule->id)
            ->whereDate('feeding_date', $request->date)
            ->first();

        return $this->sendResponse($item, 'Feeding batch schedule item retrieved successfully');
    }
} 