<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Concerns\ChecksScheduleAccess;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Services\FeedingScheduleRangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FeedingScheduleController extends ApiController
{
    use ChecksScheduleAccess;

    public function __construct(
        private readonly FeedingScheduleRangeService $rangeService
    ) {
    }

    public function index()
    {
        $farmId = request()->route('farm') ?? request('farm_id');
        if ($farmId && !$this->canViewFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding schedules');
        }
        $query = FeedingSchedule::with(['items', 'items.feedType']);
        if ($farmId) {
            $query->where(function ($q) use ($farmId) {
                $q->where('farm_id', $farmId)
                  ->orWhereNull('farm_id');
            });
        }

        if (request()->has('poultry_type_id')) {
            $query->where('poultry_type_id', request('poultry_type_id'));
        }

        $explicitPagination = null;
        if (request()->has('pagination')) {
            $explicitPagination = filter_var(request('pagination'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } elseif (request()->has('paginate')) {
            $explicitPagination = filter_var(request('paginate'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $shouldPaginateFromQuery = filter_var(request('paginate', false), FILTER_VALIDATE_BOOLEAN);

        if ($explicitPagination === false) {
            $schedules = $query->get();
        } elseif ($explicitPagination === true || $shouldPaginateFromQuery) {
            $perPage = request('per_page') ?? request('perPage') ?? 15;
            $schedules = $query->paginate($perPage);
        } else {
            $schedules = $query->get();
        }
        return $this->sendResponse($schedules, 'Feeding schedules retrieved successfully');
    }

    public function store(Request $request)
    {
        $type = $request->input('type', 'default');
        $routeFarmId = $request->route('farm');
        $bodyFarmId = $request->input('farm_id');
        $farmId = $routeFarmId ?? $bodyFarmId;

        if ($type === 'user') {
            if (!$farmId) {
                return $this->sendValidationError('Validation failed', ['farm_id' => ['farm_id is required when type is user']]);
            }
            if (!$this->canCreateFarmSchedules(auth()->user(), $farmId)) {
                return $this->sendUnauthorizedError('You do not have permission to create feeding schedules');
            }
        }

        $validator = Validator::make($request->all(), array_merge([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'poultry_type_id' => 'nullable|exists:poultry_types,id',
            'farm_id' => 'nullable|exists:farms,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'items' => 'nullable|array',
        ], $this->itemRules(true)));

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $items = $request->input('items', []);
        $normalized = $this->normalizeItemRanges($items);
        $validation = $this->rangeService->validateRanges($normalized);
        if (!empty($validation['errors'])) {
            return $this->sendValidationError('Validation failed', [
                'items' => $validation['errors'],
                'conflicts' => $validation['conflicts'],
            ]);
        }

        $schedule = DB::transaction(function () use ($request, $farmId, $normalized) {
            $schedule = FeedingSchedule::create([
                'title' => $request->title,
                'description' => $request->description,
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
                'type' => 'user',
                'farm_id' => $farmId,
                'poultry_type_id' => $request->poultry_type_id,
            ]);

            foreach ($normalized as $item) {
                FeedingScheduleItem::create([
                    'feeding_schedule_id' => $schedule->id,
                    'feed_type_id' => $item['feed_type_id'],
                    'feeding_times' => $item['feeding_times'],
                    'quantity' => $item['quantity'] ?? 0,
                    'start_day' => $item['start_day'],
                    'end_day' => $item['end_day'],
                    'feeding_day' => $item['start_day'],
                ]);
            }

            return $schedule->load(['items', 'items.feedType']);
        });

        $payload = $schedule->toArray();
        if (!empty($validation['warnings'])) {
            $payload['range_warnings'] = $validation['warnings'];
        }

        return $this->sendResponse($payload, 'Feeding schedule created successfully', 201);
    }

    public function show($id)
    {
        $schedule = FeedingSchedule::with(['items', 'items.feedType'])->findOrFail($id);
        $farmId = $schedule->farm_id ?? null;
        if ($farmId && !$this->canViewFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this feeding schedule');
        }
        return $this->sendResponse($schedule, 'Feeding schedule retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $schedule = FeedingSchedule::findOrFail($id);
        $farmId = $schedule->farm_id ?? null;
        if ($farmId && !$this->canUpdateFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this feeding schedule');
        }

        $validator = Validator::make($request->all(), array_merge([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'type' => 'nullable|in:default,user',
            'items' => 'nullable|array',
            'items.*.id' => 'nullable|integer|exists:feeding_schedule_items,id',
        ], $this->itemRules(true)));

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $warnings = [];
        $normalized = [];

        if ($request->has('items')) {
            $normalized = $this->normalizeItemRanges($request->input('items', []));
            $validation = $this->rangeService->validateRanges($normalized);
            if (!empty($validation['errors'])) {
                return $this->sendValidationError('Validation failed', [
                    'items' => $validation['errors'],
                    'conflicts' => $validation['conflicts'],
                ]);
            }
            $warnings = $validation['warnings'];
        }

        DB::transaction(function () use ($request, $schedule, $normalized) {
            $schedule->fill($request->only(['title', 'description', 'start_date', 'end_date', 'type']));
            $schedule->save();

            if (!$request->has('items')) {
                return;
            }

            $existingIds = $schedule->items()->pluck('id')->all();
            $keptIds = [];

            foreach ($normalized as $itemData) {
                if (!empty($itemData['id']) && in_array((int) $itemData['id'], $existingIds, true)) {
                    $item = FeedingScheduleItem::where('feeding_schedule_id', $schedule->id)
                        ->where('id', $itemData['id'])
                        ->firstOrFail();
                    $item->update([
                        'feed_type_id' => $itemData['feed_type_id'],
                        'feeding_times' => $itemData['feeding_times'],
                        'quantity' => $itemData['quantity'] ?? 0,
                        'start_day' => $itemData['start_day'],
                        'end_day' => $itemData['end_day'],
                        'feeding_day' => $itemData['start_day'],
                    ]);
                    $keptIds[] = (int) $item->id;
                } else {
                    $created = FeedingScheduleItem::create([
                        'feeding_schedule_id' => $schedule->id,
                        'feed_type_id' => $itemData['feed_type_id'],
                        'feeding_times' => $itemData['feeding_times'],
                        'quantity' => $itemData['quantity'] ?? 0,
                        'start_day' => $itemData['start_day'],
                        'end_day' => $itemData['end_day'],
                        'feeding_day' => $itemData['start_day'],
                    ]);
                    $keptIds[] = (int) $created->id;
                }
            }

            $toDelete = array_diff($existingIds, $keptIds);
            if (!empty($toDelete)) {
                FeedingScheduleItem::where('feeding_schedule_id', $schedule->id)
                    ->whereIn('id', $toDelete)
                    ->delete();
            }
        });

        $schedule->refresh()->load(['items', 'items.feedType']);
        $payload = $schedule->toArray();
        if (!empty($warnings)) {
            $payload['range_warnings'] = $warnings;
        }

        return $this->sendResponse($payload, 'Feeding schedule updated successfully');
    }

    public function destroy($id)
    {
        $schedule = FeedingSchedule::findOrFail($id);
        $farmId = $schedule->farm_id ?? null;
        if ($farmId && !$this->canDeleteFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this feeding schedule');
        }
        $schedule->delete();
        return $this->sendResponse(null, 'Feeding schedule deleted successfully');
    }

    /**
     * @return array<string, string>
     */
    private function itemRules(bool $nested): array
    {
        $prefix = $nested ? 'items.*.' : '';

        return [
            $prefix . 'feed_type_id' => ($nested ? 'required_with:items|' : 'required|') . 'exists:poultry_feed_types,id',
            $prefix . 'feeding_times' => ($nested ? 'required_with:items|' : 'required|') . 'array',
            $prefix . 'feeding_times.*.time' => 'required_with:' . ($nested ? 'items.*.feeding_times' : 'feeding_times') . '|string',
            $prefix . 'feeding_times.*.percentage' => 'required_with:' . ($nested ? 'items.*.feeding_times' : 'feeding_times') . '|numeric|min:0|max:100',
            $prefix . 'quantity' => 'nullable|numeric',
            $prefix . 'feeding_day' => 'nullable|integer|min:1',
            $prefix . 'start_day' => 'nullable|integer|min:1',
            $prefix . 'end_day' => 'nullable|integer|min:1',
            $prefix . 'open_ended' => 'nullable|boolean',
            $prefix . 'is_open_ended' => 'nullable|boolean',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function normalizeItemRanges(array $items): array
    {
        $normalized = [];
        foreach ($items as $index => $item) {
            $hasExplicitEnd = array_key_exists('end_day', $item);
            $start = isset($item['start_day'])
                ? (int) $item['start_day']
                : (isset($item['feeding_day']) ? (int) $item['feeding_day'] : ($index + 1));

            if ($hasExplicitEnd) {
                $end = ($item['end_day'] !== null && $item['end_day'] !== '')
                    ? (int) $item['end_day']
                    : null;
            } elseif (!empty($item['open_ended']) || !empty($item['is_open_ended'])) {
                $end = null;
            } elseif (!isset($item['start_day']) && isset($item['feeding_day'])) {
                // Legacy single-day row.
                $end = (int) $item['feeding_day'];
            } else {
                // start_day without end_day => closed 1-day range by default.
                $end = $start;
            }

            $normalized[] = [
                'id' => $item['id'] ?? null,
                'feed_type_id' => $item['feed_type_id'],
                'feeding_times' => $item['feeding_times'] ?? [],
                'quantity' => $item['quantity'] ?? 0,
                'start_day' => $start,
                'end_day' => $end,
            ];
        }

        return $normalized;
    }
}
