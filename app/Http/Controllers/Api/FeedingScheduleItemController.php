<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Concerns\ChecksScheduleAccess;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Services\FeedingScheduleRangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class FeedingScheduleItemController extends ApiController
{
    use ChecksScheduleAccess;

    public function __construct(
        private readonly FeedingScheduleRangeService $rangeService
    ) {
    }

    public function index()
    {
        $feedingScheduleId = request('feeding_schedule_id');
        $farmId = null;
        if ($feedingScheduleId) {
            $feedingSchedule = FeedingSchedule::find($feedingScheduleId);
            $farmId = $feedingSchedule ? $feedingSchedule->farm_id : null;
        }
        if ($farmId && !$this->canViewFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view feeding schedule items');
        }

        $query = FeedingScheduleItem::with(['schedule', 'feedType']);
        if ($feedingScheduleId) {
            $query->where('feeding_schedule_id', $feedingScheduleId);
        }

        $items = $query->orderBy('start_day')->paginate(15);
        return $this->sendResponse($items, 'Feeding schedule items retrieved successfully');
    }

    public function store(Request $request)
    {
        $feedingSchedule = FeedingSchedule::find($request->feeding_schedule_id);
        $farmId = $feedingSchedule ? $feedingSchedule->farm_id : null;
        if ($farmId && !$this->canCreateFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to create feeding schedule items');
        }

        $validator = Validator::make($request->all(), [
            'feeding_schedule_id' => 'required|exists:feeding_schedules,id',
            'feed_type_id' => 'required|exists:poultry_feed_types,id',
            'feeding_times' => 'required|array',
            'feeding_times.*.time' => 'required|string',
            'feeding_times.*.percentage' => 'required|numeric|min:0|max:100',
            'quantity' => 'required|numeric|min:0',
            'feeding_day' => 'nullable|integer|min:1',
            'start_day' => 'nullable|integer|min:1',
            'end_day' => 'nullable|integer|min:1',
            'open_ended' => 'nullable|boolean',
            'is_open_ended' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        if (!$request->filled('start_day') && !$request->filled('feeding_day')) {
            return $this->sendValidationError('Validation failed', [
                'start_day' => ['start_day or feeding_day is required'],
            ]);
        }

        [$startDay, $endDay] = $this->resolveRange($request);

        $siblings = $this->siblingRanges((int) $request->feeding_schedule_id);
        $siblings[] = [
            'start_day' => $startDay,
            'end_day' => $endDay,
        ];
        $validation = $this->rangeService->validateRanges($siblings);
        if (!empty($validation['errors'])) {
            return $this->sendValidationError('Validation failed', [
                'items' => $validation['errors'],
                'conflicts' => $validation['conflicts'],
            ]);
        }

        $item = FeedingScheduleItem::create([
            'feeding_schedule_id' => $request->feeding_schedule_id,
            'feed_type_id' => $request->feed_type_id,
            'feeding_times' => $request->feeding_times,
            'quantity' => $request->quantity,
            'start_day' => $startDay,
            'end_day' => $endDay,
            'feeding_day' => $startDay,
        ]);

        $payload = $item->toArray();
        if (!empty($validation['warnings'])) {
            $payload['range_warnings'] = $validation['warnings'];
        }

        return $this->sendResponse($payload, 'Feeding schedule item created successfully', 201);
    }

    public function show($id)
    {
        $item = FeedingScheduleItem::with(['schedule', 'feedType'])->findOrFail($id);
        $farmId = $item->schedule ? $item->schedule->farm_id : null;
        if ($farmId && !$this->canViewFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to view this feeding schedule item');
        }
        return $this->sendResponse($item, 'Feeding schedule item retrieved successfully');
    }

    public function update(Request $request, $id)
    {
        $item = FeedingScheduleItem::findOrFail($id);
        $farmId = $item->schedule ? $item->schedule->farm_id : null;
        if ($farmId && !$this->canUpdateFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this feeding schedule item');
        }

        $validator = Validator::make($request->all(), [
            'feeding_schedule_id' => 'sometimes|required|exists:feeding_schedules,id',
            'feed_type_id' => 'sometimes|required|exists:poultry_feed_types,id',
            'feeding_times' => 'sometimes|required|array',
            'feeding_times.*.time' => 'required_with:feeding_times|string',
            'feeding_times.*.percentage' => 'required_with:feeding_times|numeric|min:0|max:100',
            'quantity' => 'sometimes|required|numeric|min:0',
            'feeding_day' => 'nullable|integer|min:1',
            'start_day' => 'nullable|integer|min:1',
            'end_day' => 'nullable|integer|min:1',
            'open_ended' => 'nullable|boolean',
            'is_open_ended' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $startDay = $request->has('start_day')
            ? (int) $request->start_day
            : ($request->has('feeding_day') ? (int) $request->feeding_day : (int) $item->start_day);

        if ($request->has('end_day') || $request->boolean('open_ended') || $request->boolean('is_open_ended')) {
            if ($request->boolean('open_ended') || $request->boolean('is_open_ended') || $request->input('end_day') === null) {
                $endDay = null;
            } else {
                $endDay = (int) $request->end_day;
            }
        } else {
            $endDay = $item->end_day !== null ? (int) $item->end_day : null;
        }

        $siblings = $this->siblingRanges((int) $item->feeding_schedule_id, (int) $item->id);
        $siblings[] = [
            'id' => $item->id,
            'start_day' => $startDay,
            'end_day' => $endDay,
        ];
        $validation = $this->rangeService->validateRanges($siblings);
        if (!empty($validation['errors'])) {
            return $this->sendValidationError('Validation failed', [
                'items' => $validation['errors'],
                'conflicts' => $validation['conflicts'],
            ]);
        }

        $data = $request->only(['feeding_schedule_id', 'feed_type_id', 'feeding_times', 'quantity']);
        $data['start_day'] = $startDay;
        $data['end_day'] = $endDay;
        $data['feeding_day'] = $startDay;
        $item->update($data);

        $payload = $item->fresh()->toArray();
        if (!empty($validation['warnings'])) {
            $payload['range_warnings'] = $validation['warnings'];
        }

        return $this->sendResponse($payload, 'Feeding schedule item updated successfully');
    }

    public function destroy($id)
    {
        $item = FeedingScheduleItem::findOrFail($id);
        $farmId = $item->schedule ? $item->schedule->farm_id : null;
        if ($farmId && !$this->canDeleteFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to delete this feeding schedule item');
        }
        $item->delete();
        return $this->sendResponse(null, 'Feeding schedule item deleted successfully');
    }

    /**
     * Split a range at a day: original becomes start..(day-1), new is day..end.
     */
    public function split(Request $request, $farm, $id)
    {
        $item = FeedingScheduleItem::with('schedule')->findOrFail($id);
        $farmId = $item->schedule ? $item->schedule->farm_id : null;
        if ($farmId && !$this->canUpdateFarmSchedules(auth()->user(), $farmId)) {
            return $this->sendUnauthorizedError('You do not have permission to update this feeding schedule item');
        }

        $validator = Validator::make($request->all(), [
            'day' => 'required|integer|min:2',
            'feed_type_id' => 'nullable|exists:poultry_feed_types,id',
            'quantity' => 'nullable|numeric|min:0',
            'feeding_times' => 'nullable|array',
            'feeding_times.*.time' => 'required_with:feeding_times|string',
            'feeding_times.*.percentage' => 'required_with:feeding_times|numeric|min:0|max:100',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        try {
            [$left, $right] = $this->rangeService->splitAt(
                $item,
                (int) $request->day,
                $request->only(['feed_type_id', 'quantity', 'feeding_times'])
            );
        } catch (InvalidArgumentException $e) {
            return $this->sendValidationError('Validation failed', ['day' => [$e->getMessage()]]);
        }

        return $this->sendResponse([
            'original' => $left,
            'created' => $right,
        ], 'Feeding schedule item split successfully');
    }

    /**
     * @return array{0:int,1:?int}
     */
    private function resolveRange(Request $request): array
    {
        $start = $request->filled('start_day')
            ? (int) $request->start_day
            : (int) $request->feeding_day;

        if ($request->boolean('open_ended') || $request->boolean('is_open_ended')) {
            return [$start, null];
        }

        if ($request->has('end_day')) {
            $end = $request->input('end_day');
            return [$start, $end === null || $end === '' ? null : (int) $end];
        }

        // Legacy feeding_day-only or start without end => 1-day closed range.
        return [$start, $start];
    }

    /**
     * @return list<array{id?:int,start_day:int,end_day:?int}>
     */
    private function siblingRanges(int $scheduleId, ?int $ignoreId = null): array
    {
        return FeedingScheduleItem::where('feeding_schedule_id', $scheduleId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get()
            ->map(fn (FeedingScheduleItem $item) => [
                'id' => $item->id,
                'start_day' => (int) $item->start_day,
                'end_day' => $item->end_day !== null ? (int) $item->end_day : null,
            ])
            ->values()
            ->all();
    }
}
