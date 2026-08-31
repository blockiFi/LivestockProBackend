<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Models\Schedule;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportItem;
use App\Models\ScheduleItem;
use App\Models\PoultryFeedType;
use App\Services\ScheduleImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AiScheduleImportController extends ApiController
{
    public function __construct(protected ScheduleImportService $service)
    {
    }

    protected function ensureCanCreateAny(Request $request, int $farmId): bool
    {
        $user = $request->user();
        return $user->can('create schedules', 'api', $farmId)
            || $user->can('create feeding schedules', 'api', $farmId);
    }

    protected function ensureCanViewAny(Request $request, int $farmId): bool
    {
        $user = $request->user();
        return $user->can('view schedules', 'api', $farmId)
            || $user->can('view feeding schedules', 'api', $farmId);
    }

    /**
     * Create an AI schedule-import draft from an uploaded PDF/image.
     */
    public function store(Request $request, $farm)
    {
        $farm = Farm::findOrFail($farm);

        if (!$this->ensureCanCreateAny($request, $farm->id)) {
            return $this->sendUnauthorizedError('You do not have permission to import schedules');
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:10240|mimes:pdf,jpeg,jpg,png,webp',
            'poultry_type_id' => 'required|integer|exists:poultry_types,id',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $sourceType = $ext === 'pdf' ? 'pdf' : 'image';
        $path = $file->store('schedule-imports', 'public');

        $draft = ScheduleImport::create([
            'farm_id' => $farm->id,
            'created_by' => $request->user()->id,
            'source_type' => $sourceType,
            'source_path' => $path,
            'status' => 'draft',
        ]);

        // Run extraction (best-effort). If extraction fails, keep draft and return warnings.
        $result = $this->service->extractAndPopulate($draft, (int) $request->input('poultry_type_id'));

        return $this->sendResponse([
            'draft' => $draft->fresh()->load('items'),
            'ai_available' => $result['ai_available'] ?? false,
            'warnings' => $result['warnings'] ?? [],
        ], 'Schedule import draft created successfully', 201);
    }

    public function show(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);

        if (!$this->ensureCanViewAny($request, $farm->id)) {
            return $this->sendUnauthorizedError('You do not have permission to view schedule imports');
        }

        $draft = ScheduleImport::with('items')->where('farm_id', $farm->id)->findOrFail($id);
        return $this->sendResponse($draft, 'Schedule import draft retrieved successfully');
    }

    /**
     * Re-run AI extraction for an existing draft (retry after transient failures).
     */
    public function extract(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);

        if (!$this->ensureCanCreateAny($request, $farm->id)) {
            return $this->sendUnauthorizedError('You do not have permission to import schedules');
        }

        $draft = ScheduleImport::with('items')->where('farm_id', $farm->id)->findOrFail($id);
        if ($draft->status !== 'draft') {
            return $this->sendValidationError('Invalid status', ['status' => ['Only drafts can be re-extracted']]);
        }

        $validator = Validator::make($request->all(), [
            'poultry_type_id' => 'required|integer|exists:poultry_types,id',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $result = $this->service->extractAndPopulate($draft, (int) $request->input('poultry_type_id'));

        return $this->sendResponse([
            'draft' => $draft->fresh()->load('items'),
            'ai_available' => $result['ai_available'] ?? false,
            'warnings' => $result['warnings'] ?? [],
        ], 'Schedule import draft extracted successfully');
    }

    /**
     * Update a draft and its items (user edits before confirm).
     */
    public function update(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);

        if (!$this->ensureCanCreateAny($request, $farm->id)) {
            return $this->sendUnauthorizedError('You do not have permission to update schedule imports');
        }

        $draft = ScheduleImport::with('items')->where('farm_id', $farm->id)->findOrFail($id);
        if ($draft->status !== 'draft') {
            return $this->sendValidationError('Draft is not editable', ['status' => ['Only drafts can be edited']]);
        }

        $validator = Validator::make($request->all(), [
            'feeding_layout' => 'sometimes|string|in:range,per_day',
            'items' => 'required|array',
            'items.*.id' => 'nullable|integer',
            'items.*.kind' => 'required|string|in:vaccination,medication,feeding',
            'items.*.age_days' => 'nullable|integer|min:0',
            'items.*.feeding_day' => 'nullable|integer|min:1',
            'items.*.start_day' => 'nullable|integer|min:1',
            'items.*.end_day' => 'nullable|integer|min:1',
            'items.*.name' => 'nullable|string|max:255',
            'items.*.dose' => 'nullable|integer|min:1',
            'items.*.withdrawal_period_days' => 'nullable|integer|min:0',
            'items.*.storage_instructions' => 'nullable|string',
            'items.*.description' => 'nullable|string',
            'items.*.feed_type_id' => 'nullable|integer|exists:poultry_feed_types,id',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.feeding_times' => 'nullable|array',
            'items.*.feeding_times.*.time' => 'required_with:items.*.feeding_times|string',
            'items.*.feeding_times.*.percentage' => 'required_with:items.*.feeding_times|numeric|min:0|max:100',
            'items.*.confidence' => 'nullable|numeric|min:0|max:100',
            'items.*.notes' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        DB::transaction(function () use ($draft, $request) {
            if ($request->has('feeding_layout')) {
                $draft->update(['feeding_layout' => $request->input('feeding_layout')]);
            }

            $existingIds = $draft->items->pluck('id')->all();
            $incoming = $request->input('items', []);
            $incomingIds = array_values(array_filter(array_map(fn ($i) => $i['id'] ?? null, $incoming)));

            // Delete removed items
            $toDelete = array_diff($existingIds, $incomingIds);
            if (!empty($toDelete)) {
                ScheduleImportItem::where('schedule_import_id', $draft->id)->whereIn('id', $toDelete)->delete();
            }

            // Upsert incoming items
            foreach ($incoming as $item) {
                $kind = $item['kind'];
                $startDay = null;
                $endDay = null;
                $feedingDay = null;

                if ($kind === 'feeding') {
                    $startDay = isset($item['start_day'])
                        ? (int) $item['start_day']
                        : (isset($item['feeding_day']) ? (int) $item['feeding_day'] : null);
                    if (array_key_exists('end_day', $item)) {
                        $endDay = ($item['end_day'] === null || $item['end_day'] === '')
                            ? null
                            : (int) $item['end_day'];
                    } elseif ($startDay !== null) {
                        $endDay = $startDay;
                    }
                    $feedingDay = $startDay;
                }

                $payload = [
                    'kind' => $kind,
                    'age_days' => $item['age_days'] ?? null,
                    'feeding_day' => $feedingDay,
                    'start_day' => $startDay,
                    'end_day' => $endDay,
                    'name' => $item['name'] ?? null,
                    'dose' => $item['dose'] ?? null,
                    'withdrawal_period_days' => $item['withdrawal_period_days'] ?? null,
                    'storage_instructions' => $item['storage_instructions'] ?? null,
                    'description' => $item['description'] ?? null,
                    'feed_type_id' => $item['feed_type_id'] ?? null,
                    'quantity' => $item['quantity'] ?? null,
                    'feeding_times' => $item['feeding_times'] ?? null,
                    'confidence' => $item['confidence'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ];

                if (!empty($item['id'])) {
                    ScheduleImportItem::where('schedule_import_id', $draft->id)->where('id', $item['id'])->update($payload);
                } else {
                    $payload['schedule_import_id'] = $draft->id;
                    ScheduleImportItem::create($payload);
                }
            }
        });

        return $this->sendResponse(
            $draft->fresh()->load('items'),
            'Schedule import draft updated successfully'
        );
    }

    /**
     * Confirm a draft and generate schedules.
     */
    public function confirm(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);

        if (!$this->ensureCanCreateAny($request, $farm->id)) {
            return $this->sendUnauthorizedError('You do not have permission to confirm schedule imports');
        }

        $draft = ScheduleImport::with('items')->where('farm_id', $farm->id)->findOrFail($id);
        if ($draft->status !== 'draft') {
            return $this->sendValidationError('Invalid status', ['status' => ['Only drafts can be confirmed']]);
        }

        $validator = Validator::make($request->all(), [
            'poultry_type_id' => 'required|integer|exists:poultry_types,id',
            'medication_schedule_name' => 'nullable|string|max:255',
            'medication_schedule_description' => 'nullable|string|max:2000',
            'vaccination_schedule_name' => 'nullable|string|max:255',
            'vaccination_schedule_description' => 'nullable|string|max:2000',
            'feeding_schedule_title' => 'nullable|string|max:255',
            'feeding_schedule_description' => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $poultryTypeId = (int) $request->input('poultry_type_id');

        $items = $draft->items;
        $hasMed = $items->where('kind', 'medication')->count() > 0;
        $hasVac = $items->where('kind', 'vaccination')->count() > 0;
        $hasFeed = $items->where('kind', 'feeding')->count() > 0;

        if (($hasMed || $hasVac) && !$request->user()->can('create schedules', 'api', $farm->id)) {
            return $this->sendUnauthorizedError('You do not have permission to create medication/vaccination schedules');
        }
        if ($hasFeed && !$this->ensureCanCreateAny($request, $farm->id)) {
            return $this->sendUnauthorizedError('You do not have permission to create feeding schedules');
        }

        try {
            $created = DB::transaction(function () use ($draft, $farm, $items, $poultryTypeId, $request, $hasMed, $hasVac, $hasFeed) {
            $createdIds = [
                'medication_schedule_id' => null,
                'vaccination_schedule_id' => null,
                'feeding_schedule_id' => null,
            ];

            if ($hasMed) {
                $schedule = Schedule::create([
                    'poultry_type_id' => $poultryTypeId,
                    'farm_id' => $farm->id,
                    'name' => $request->input('medication_schedule_name') ?: 'Imported Medication Schedule (AI)',
                    'description' => $request->input('medication_schedule_description') ?: 'Generated from document import',
                    'schedule_type' => 'medication',
                    'type' => 'user',
                ]);
                foreach ($items->where('kind', 'medication') as $it) {
                    ScheduleItem::create([
                        'schedule_id' => $schedule->id,
                        'age_days' => (int) ($it->age_days ?? 0),
                        'poultry_medication_id' => null,
                        'poultry_vaccine_id' => null,
                        'name' => $it->name ?: 'Medication',
                        'dose' => (int) ($it->dose ?? 1),
                        'withdrawal_period_days' => (int) ($it->withdrawal_period_days ?? 0),
                        'storage_instructions' => $it->storage_instructions,
                        'description' => $it->description,
                    ]);
                }
                $createdIds['medication_schedule_id'] = $schedule->id;
            }

            if ($hasVac) {
                $schedule = Schedule::create([
                    'poultry_type_id' => $poultryTypeId,
                    'farm_id' => $farm->id,
                    'name' => $request->input('vaccination_schedule_name') ?: 'Imported Vaccination Schedule (AI)',
                    'description' => $request->input('vaccination_schedule_description') ?: 'Generated from document import',
                    'schedule_type' => 'vaccination',
                    'type' => 'user',
                ]);
                foreach ($items->where('kind', 'vaccination') as $it) {
                    ScheduleItem::create([
                        'schedule_id' => $schedule->id,
                        'age_days' => (int) ($it->age_days ?? 0),
                        'poultry_medication_id' => null,
                        'poultry_vaccine_id' => null,
                        'name' => $it->name ?: 'Vaccination',
                        'dose' => (int) ($it->dose ?? 1),
                        'withdrawal_period_days' => (int) ($it->withdrawal_period_days ?? 0),
                        'storage_instructions' => $it->storage_instructions,
                        'description' => $it->description,
                    ]);
                }
                $createdIds['vaccination_schedule_id'] = $schedule->id;
            }

            if ($hasFeed) {
                $feeding = FeedingSchedule::create([
                    'title' => $request->input('feeding_schedule_title') ?: 'Imported Feeding Schedule (AI)',
                    'description' => $request->input('feeding_schedule_description') ?: 'Generated from document import',
                    // This is a schedule template (not a batch schedule). Use a neutral date range.
                    // Client can edit these later if needed.
                    'start_date' => now()->toDateString(),
                    'end_date' => null,
                    'farm_id' => $farm->id,
                    'type' => 'user',
                    'poultry_type_id' => $poultryTypeId,
                ]);

                $rangeService = app(\App\Services\FeedingScheduleRangeService::class);
                $rangeCheckPayload = [];
                $normalizedFeedItems = [];

                foreach ($items->where('kind', 'feeding') as $idx => $it) {
                    $feedTypeId = $it->feed_type_id;
                    if (!$feedTypeId && $it->name) {
                        // Best-effort match by name (farm-specific first, then global).
                        $match = PoultryFeedType::where('poultry_type_id', $poultryTypeId)
                            ->where(function ($q) use ($farm) {
                                $q->where('farm_id', $farm->id)->orWhereNull('farm_id');
                            })
                            ->where('name', $it->name)
                            ->first();
                        if ($match) {
                            $feedTypeId = $match->id;
                        }
                    }

                    if (!$feedTypeId) {
                        throw new \InvalidArgumentException(
                            'Missing feed_type_id for feeding item (start_day=' . ($it->start_day ?? $it->feeding_day ?? 'null') . '). Please select a feed type.'
                        );
                    }

                    $startDay = (int) ($it->start_day ?? $it->feeding_day ?? 1);
                    if ($it->end_day !== null) {
                        $endDay = (int) $it->end_day;
                    } elseif ($it->start_day !== null) {
                        // Explicit start_day with null end_day → open-ended
                        $endDay = null;
                    } else {
                        // Legacy feeding_day-only draft rows → closed 1-day
                        $endDay = $startDay;
                    }

                    $rangeCheckPayload[] = [
                        'id' => $it->id ?? $idx,
                        'start_day' => $startDay,
                        'end_day' => $endDay,
                    ];
                    $normalizedFeedItems[] = [
                        'feed_type_id' => $feedTypeId,
                        'feeding_times' => $it->feeding_times ?: [],
                        'quantity' => $it->quantity ?? 0,
                        'start_day' => $startDay,
                        'end_day' => $endDay,
                    ];
                }

                $check = $rangeService->validateRanges($rangeCheckPayload);
                if (!empty($check['errors'])) {
                    throw new \InvalidArgumentException(implode(' ', $check['errors']));
                }

                foreach ($normalizedFeedItems as $row) {
                    FeedingScheduleItem::create([
                        'feeding_schedule_id' => $feeding->id,
                        'feed_type_id' => $row['feed_type_id'],
                        'feeding_times' => $row['feeding_times'],
                        'quantity' => $row['quantity'],
                        'start_day' => $row['start_day'],
                        'end_day' => $row['end_day'],
                        'feeding_day' => $row['start_day'],
                    ]);
                }

                if (($draft->feeding_layout ?? 'range') !== 'per_day') {
                    $rangeService->collapseIdenticalRuns($feeding->fresh(['items']));
                }

                $createdIds['feeding_schedule_id'] = $feeding->id;
            }

            $draft->update(['status' => 'confirmed']);

            return $createdIds;
            });
        } catch (\InvalidArgumentException $e) {
            return $this->sendValidationError('Validation failed', ['feeding' => [$e->getMessage()]]);
        }

        return $this->sendResponse($created, 'Schedules generated successfully');
    }

    /**
     * Optional: delete a draft + its stored file.
     */
    public function destroy(Request $request, $farm, $id)
    {
        $farm = Farm::findOrFail($farm);

        if (!$this->ensureCanCreateAny($request, $farm->id)) {
            return $this->sendUnauthorizedError('You do not have permission to delete schedule imports');
        }

        $draft = ScheduleImport::where('farm_id', $farm->id)->findOrFail($id);
        if ($draft->source_path) {
            Storage::disk('public')->delete($draft->source_path);
        }
        $draft->delete();

        return $this->sendResponse(null, 'Schedule import deleted successfully');
    }
}

