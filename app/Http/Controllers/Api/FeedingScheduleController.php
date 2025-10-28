<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class FeedingScheduleController extends ApiController
{
    public function index()
    {
        $farmId = request()->route('farm') ?? request('farm_id');
        if ($farmId && !auth()->user()->can('view feeding schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding schedules');
        }
        $query = FeedingSchedule::with(['items', 'items.feedType']);
        if ($farmId) {
            $query->where(function ($q) use ($farmId) {
                $q->where('farm_id', $farmId)
                  ->orWhereNull('farm_id'); // include default schedules
            });
        }
        $perPage = request('per_page') ?? request('perPage') ?? 15;
        $schedules = $query->paginate($perPage);
        return $this->sendResponse($schedules, 'Feeding schedules retrieved successfully');
    }

    public function store(Request $request)
    {
        // Determine type and farm
        $type = $request->input('type', 'default');
        $routeFarmId = $request->route('farm');
        $bodyFarmId = $request->input('farm_id');
        $farmId = $type === 'user' ? ($routeFarmId ?? $bodyFarmId) : ($routeFarmId ?? $bodyFarmId); // keep route precedence

        if ($type === 'user') {
            if (!$farmId) {
                return $this->sendValidationError('Validation failed', ['farm_id' => ['farm_id is required when type is user']]);
            }
            if (!auth()->user()->can('create feeding schedules', 'api', $farmId)) {
                return $this->sendUnauthorizedError('You do not have permission to create feeding schedules');
            }
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'type' => 'nullable|in:default,user',
            'farm_id' => 'nullable|exists:farms,id',
            'items' => 'nullable|array',
            'items.*.feed_type_id' => 'required_with:items|exists:poultry_feed_types,id',
            'items.*.feeding_times' => 'required_with:items|array',
            'items.*.feeding_times.*.time' => 'required_with:items.*.feeding_times|string',
            'items.*.feeding_times.*.percentage' => 'required_with:items.*.feeding_times|numeric|min:0|max:100',
            'items.*.quantity' => 'nullable|numeric',
            'items.*.feeding_day' => 'nullable|integer|min:1',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $schedule = DB::transaction(function () use ($request, $farmId, $type) {
            $schedule = FeedingSchedule::create([
                'title' => $request->title,
                'description' => $request->description,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'farm_id' => $type === 'user' ? $farmId : null,
                'type' => $type ?? 'default',
            ]);

            $items = $request->input('items', []);
            foreach ($items as $index => $item) {
                FeedingScheduleItem::create([
                    'feeding_schedule_id' => $schedule->id,
                    'feed_type_id' => $item['feed_type_id'],
                    'feeding_times' => $item['feeding_times'],
                    'quantity' => $item['quantity'] ?? 0,
                    'feeding_day' => $item['feeding_day'] ?? ($index + 1),
                ]);
            }

            return $schedule->load('items');
        });

        return $this->sendResponse($schedule, 'Feeding schedule created successfully', 201);
    }

    public function show($id)
    {
        $schedule = FeedingSchedule::with('items')->findOrFail($id);
        $farmId = $schedule->farm_id ?? null;
        if ($farmId && !auth()->user()->can('view feeding schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this feeding schedule');
        }
        return $this->sendResponse($schedule, 'Feeding schedule retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $schedule = FeedingSchedule::findOrFail($id);
        $farmId = $schedule->farm_id ?? null;
        if ($farmId && !auth()->user()->can('update feeding schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this feeding schedule');
        }
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'type' => 'nullable|in:default,user',
            'farm_id' => 'nullable|exists:farms,id',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $schedule->update($request->only(['title', 'description', 'start_date', 'end_date', 'type', 'farm_id']));
        return $this->sendResponse($schedule, 'Feeding schedule updated successfully');
    }

    public function destroy($id)
    {
        $schedule = FeedingSchedule::findOrFail($id);
        $farmId = $schedule->farm_id ?? null;
        if ($farmId && !auth()->user()->can('delete feeding schedules', 'api', $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this feeding schedule');
        }
        $schedule->delete();
        return $this->sendResponse(null, 'Feeding schedule deleted successfully');
    }
}