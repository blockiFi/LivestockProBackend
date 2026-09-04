<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\BatchSchedule;
use App\Models\FeedingBatchSchedule;
use App\Models\FeedingBatchScheduleItem;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockExpenditure;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryFlockWeightReport;
use App\Models\PoultryMortalityReport;
use App\Services\FeedingDayService;
use App\Services\FeedingScheduleRangeService;
use App\Services\FeedUsageInventoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FlockDailyRecordController extends ApiController
{
    private const AUTO_CREATED_NOTE = 'Auto-created from daily record entry.';

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
            'mortality' => 'nullable|integer|min:0',
            'culling_count' => 'nullable|integer|min:0',
            'culls' => 'nullable|integer|min:0',
            'average_weight_kg' => 'nullable|numeric|min:0',
            'avg_weight_grams' => 'nullable|numeric|min:0',
            'min_weight_grams' => 'nullable|numeric|min:0',
            'max_weight_grams' => 'nullable|numeric|min:0',
            'sample_size' => 'nullable|integer|min:0',
            'feed_consumption_kg' => 'nullable|numeric|min:0',
            'feed_consumed_kg' => 'nullable|numeric|min:0',
            'water_consumption_liters' => 'nullable|numeric|min:0',
            'water_consumed_liters' => 'nullable|numeric|min:0',
            'egg_production_count' => 'nullable|integer|min:0',
            'eggs_collected' => 'nullable|integer|min:0',
            'eggs_broken' => 'nullable|integer|min:0',
            'egg_weight_grams' => 'nullable|numeric|min:0',
            'temperature_celsius' => 'nullable|numeric',
            'min_temperature' => 'nullable|numeric',
            'max_temperature' => 'nullable|numeric',
            'humidity_percentage' => 'nullable|numeric',
            'humidity' => 'nullable|numeric',
            'light_hours' => 'nullable|numeric|min:0|max:24',
            'notes' => 'nullable|string',
            'additional_data' => 'nullable|array',
            'poultry_feed_inventory_id' => 'nullable|exists:poultry_feed_inventories,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $flock = Flock::with('poultryType')
            ->where('id', $request->flock_id)
            ->where('farm_id', $farmId)
            ->first();

        if (!$flock) {
            return $this->sendError('Flock not found in this farm', [], 404);
        }

        if ($response = $this->ensureFlockIsActive($flock)) {
            return $response;
        }

        $normalized = $this->normalizeDailyRecordInput($request);

        $existingRecord = FlockDailyRecord::where('flock_id', $flock->id)
            ->whereDate('date', $normalized['date'])
            ->first();

        if ($existingRecord) {
            return $this->sendValidationError('Validation failed', [
                'date' => ['A daily record already exists for this date. Edit the existing record instead.'],
            ]);
        }

        try {
            DB::beginTransaction();

            $record = FlockDailyRecord::create(
                $this->buildDailyRecordPayload($farmId, $flock, $normalized, $request)
            );

            $this->updateBatchSchedules($request->flock_id, $normalized['date']);

            $feedUsageFromSchedule = $this->updateFeedingBatchSchedules(
                $request->flock_id,
                $normalized['date'],
                $normalized['feed_kg'],
                $record,
                $flock,
                $normalized['feed_inventory_id']
            );

            $this->syncMortalityRecords($farmId, $flock, $normalized['date'], $normalized['mortality']);
            $this->syncWeightReport($farmId, $flock, $normalized['date'], $normalized);
            $this->syncEggReport($farmId, $flock, $normalized['date'], $normalized);

            if ($normalized['feed_kg'] > 0 && !$feedUsageFromSchedule) {
                $this->syncStandaloneFeedUsage(
                    $farmId,
                    $flock,
                    $normalized['date'],
                    $normalized['feed_kg'],
                    $normalized['feed_inventory_id']
                );
            }

            $flock->reconcileHouseAllocations();

            DB::commit();

            return $this->sendResponse(
                $this->formatDailyRecordForApi($record->fresh()),
                'Flock daily record created successfully',
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError('Failed to create flock daily record: ' . $e->getMessage(), [], 500);
        }
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

        return $this->sendResponse(
            $this->formatDailyRecordForApi($record),
            'Flock daily record retrieved successfully'
        );
    }

    public function update(Request $request, $farmId, $id)
    {
        $record = FlockDailyRecord::where('id', $id)
            ->where('farm_id', $farmId)
            ->first();

        if (!$record) {
            return $this->sendError('Flock daily record not found in this farm', [], 404);
        }

        $flock = Flock::find($record->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'date' => 'sometimes|required|date',
            'mortality_count' => 'nullable|integer|min:0',
            'mortality' => 'nullable|integer|min:0',
            'culling_count' => 'nullable|integer|min:0',
            'culls' => 'nullable|integer|min:0',
            'average_weight_kg' => 'nullable|numeric|min:0',
            'avg_weight_grams' => 'nullable|numeric|min:0',
            'min_weight_grams' => 'nullable|numeric|min:0',
            'max_weight_grams' => 'nullable|numeric|min:0',
            'sample_size' => 'nullable|integer|min:0',
            'feed_consumption_kg' => 'nullable|numeric|min:0',
            'feed_consumed_kg' => 'nullable|numeric|min:0',
            'water_consumption_liters' => 'nullable|numeric|min:0',
            'water_consumed_liters' => 'nullable|numeric|min:0',
            'egg_production_count' => 'nullable|integer|min:0',
            'eggs_collected' => 'nullable|integer|min:0',
            'eggs_broken' => 'nullable|integer|min:0',
            'egg_weight_grams' => 'nullable|numeric|min:0',
            'temperature_celsius' => 'nullable|numeric',
            'min_temperature' => 'nullable|numeric',
            'max_temperature' => 'nullable|numeric',
            'humidity_percentage' => 'nullable|numeric',
            'humidity' => 'nullable|numeric',
            'light_hours' => 'nullable|numeric|min:0|max:24',
            'notes' => 'nullable|string',
            'additional_data' => 'nullable|array',
            'poultry_feed_inventory_id' => 'nullable|exists:poultry_feed_inventories,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $flock = Flock::with('poultryType')
            ->where('id', $record->flock_id)
            ->where('farm_id', $farmId)
            ->first();

        if (!$flock) {
            return $this->sendError('Flock not found in this farm', [], 404);
        }

        $normalized = $this->normalizeDailyRecordInput($request);
        if (!$request->has('date')) {
            $normalized['date'] = $record->date instanceof Carbon
                ? $record->date->toDateString()
                : (string) $record->date;
        }

        try {
            DB::beginTransaction();

            $record->update(
                $this->buildDailyRecordPayload($farmId, $flock, $normalized, $request, true)
            );

            $this->updateBatchSchedules($record->flock_id, $normalized['date']);

            $feedUsageFromSchedule = $this->updateFeedingBatchSchedules(
                $record->flock_id,
                $normalized['date'],
                $normalized['feed_kg'],
                $record,
                $flock,
                $normalized['feed_inventory_id']
            );

            $this->syncMortalityRecords($farmId, $flock, $normalized['date'], $normalized['mortality']);
            $this->syncWeightReport($farmId, $flock, $normalized['date'], $normalized);
            $this->syncEggReport($farmId, $flock, $normalized['date'], $normalized);

            if ($normalized['feed_kg'] > 0 && !$feedUsageFromSchedule) {
                $this->syncFeedUsageQuantity(
                    $farmId,
                    $flock,
                    $normalized['date'],
                    $normalized['feed_kg'],
                    $normalized['feed_inventory_id']
                );
            }

            $flock->reconcileHouseAllocations();

            DB::commit();

            return $this->sendResponse(
                $this->formatDailyRecordForApi($record->fresh()),
                'Flock daily record updated successfully'
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError('Failed to update flock daily record: ' . $e->getMessage(), [], 500);
        }
    }

    public function destroy($farmId, $id)
    {
        $record = FlockDailyRecord::where('id', $id)
            ->where('farm_id', $farmId)
            ->first();

        if (!$record) {
            return $this->sendError('Flock daily record not found in this farm', [], 404);
        }

        $flock = Flock::find($record->flock_id);
        if ($flock && ($response = $this->ensureFlockIsActive($flock))) {
            return $response;
        }

        try {
            DB::beginTransaction();

            $this->cleanupRelatedRecordsOnDelete($record);
            $record->delete();

            if ($flock) {
                $flock->reconcileHouseAllocations();
            }

            DB::commit();

            return $this->sendResponse(null, 'Flock daily record deleted successfully');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError('Failed to delete flock daily record: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Remove child records that were auto-created or synced from this daily record.
     */
    protected function cleanupRelatedRecordsOnDelete(FlockDailyRecord $record): void
    {
        $farmId = (int) $record->farm_id;
        $flockId = (int) $record->flock_id;
        $date = $record->date instanceof Carbon
            ? $record->date->toDateString()
            : (string) $record->date;

        PoultryMortalityReport::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->whereDate('date', $date)
            ->where('notes', self::AUTO_CREATED_NOTE)
            ->delete();

        PoultryFlockWeightReport::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->whereDate('report_date', $date)
            ->where('notes', self::AUTO_CREATED_NOTE)
            ->delete();

        PoultryFlockEggReport::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->whereDate('date', $date)
            ->where('notes', 'like', self::AUTO_CREATED_NOTE . '%')
            ->delete();

        $feedingBatchSchedule = FeedingBatchSchedule::where('flock_id', $flockId)->first();
        if ($feedingBatchSchedule) {
            FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $feedingBatchSchedule->id)
                ->whereDate('feeding_date', $date)
                ->delete();
        }

        $feedUsages = PoultryFeedUsage::where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->whereDate('usage_date', $date)
            ->get();

        foreach ($feedUsages as $usage) {
            $this->deleteFeedUsageAndRestoreInventory($usage);
        }
    }

    /**
     * Delete feed usage, restore inventory, and remove linked expenditure.
     */
    protected function deleteFeedUsageAndRestoreInventory(PoultryFeedUsage $usage): void
    {
        FlockExpenditure::deleteForSource('feed_usage', $usage->id);
        FeedUsageInventoryService::restoreOnDelete($usage);
        $usage->delete();
    }

    /**
     * Update an existing feed usage quantity and optionally switch inventory batch.
     */
    protected function updateFeedUsageQuantity(
        PoultryFeedUsage $usage,
        float $newQuantityKg,
        ?int $newInventoryId = null
    ): void {
        $inventoryChanged = $newInventoryId !== null
            && (int) $newInventoryId !== (int) $usage->poultry_feed_inventory_id;

        $quantityUnchanged = round((float) $usage->quantity, 3) === round($newQuantityKg, 3);

        if (!$inventoryChanged && $quantityUnchanged) {
            // Still ensure a linked expenditure exists / is up to date.
            FlockExpenditure::recordFromFeedUsage($usage->fresh() ?? $usage);
            return;
        }

        FeedUsageInventoryService::applyUsageChange($usage, $newQuantityKg, $newInventoryId);

        $update = ['quantity' => $newQuantityKg];
        if ($inventoryChanged) {
            $newInventory = PoultryFeedInventory::findOrFail($newInventoryId);
            $update['poultry_feed_inventory_id'] = $newInventory->id;
            $update['poultry_feed_type_id'] = $newInventory->poultry_feed_type_id;
            $update['unit_cost'] = $newInventory->unit_cost ?? $usage->unit_cost;
        }

        $usage->update($update);
        FlockExpenditure::recordFromFeedUsage($usage->fresh());
    }

    /**
     * Normalize frontend and backend field names into a single shape.
     */
    protected function normalizeDailyRecordInput(Request $request): array
    {
        $mortality = $request->input('mortality_count', $request->input('mortality', 0));
        $culls = $request->input('culling_count', $request->input('culls', 0));
        $feedKg = $request->input('feed_consumption_kg', $request->input('feed_consumed_kg', 0));
        $waterL = $request->input('water_consumption_liters', $request->input('water_consumed_liters', 0));

        $avgWeightGrams = $request->input('avg_weight_grams');
        if ($avgWeightGrams === null && $request->has('average_weight_kg')) {
            $avgWeightGrams = (float) $request->input('average_weight_kg') * 1000;
        }
        $avgWeightGrams = (float) ($avgWeightGrams ?? 0);
        $minWeightGrams = (float) $request->input('min_weight_grams', 0);
        $maxWeightGrams = (float) $request->input('max_weight_grams', 0);
        $sampleSize = (int) $request->input('sample_size', 0);

        $eggsCollected = $request->input('egg_production_count', $request->input('eggs_collected', 0));
        $eggsBroken = (int) $request->input('eggs_broken', 0);
        $eggWeightGrams = (float) $request->input('egg_weight_grams', 0);

        $minTemp = $request->input('min_temperature');
        $maxTemp = $request->input('max_temperature');
        $temperature = $request->input('temperature_celsius');
        if ($temperature === null) {
            if ($minTemp !== null && $maxTemp !== null) {
                $temperature = ((float) $minTemp + (float) $maxTemp) / 2;
            } elseif ($minTemp !== null) {
                $temperature = (float) $minTemp;
            } elseif ($maxTemp !== null) {
                $temperature = (float) $maxTemp;
            } else {
                $temperature = null;
            }
        }

        $humidity = $request->input('humidity_percentage', $request->input('humidity'));
        $feedInventoryId = $request->input('poultry_feed_inventory_id');

        return [
            'date' => $request->date,
            'mortality' => (int) $mortality,
            'culls' => (int) $culls,
            'feed_kg' => (float) $feedKg,
            'water_l' => (float) $waterL,
            'avg_weight_grams' => $avgWeightGrams,
            'min_weight_grams' => $minWeightGrams,
            'max_weight_grams' => $maxWeightGrams,
            'sample_size' => $sampleSize,
            'eggs_collected' => (int) $eggsCollected,
            'eggs_broken' => $eggsBroken,
            'egg_weight_grams' => $eggWeightGrams,
            'temperature' => $temperature !== null ? (float) $temperature : null,
            'humidity' => $humidity !== null ? (float) $humidity : null,
            'light_hours' => $request->has('light_hours') ? (float) $request->input('light_hours') : null,
            'min_temperature' => $minTemp !== null ? (float) $minTemp : null,
            'max_temperature' => $maxTemp !== null ? (float) $maxTemp : null,
            'notes' => $request->input('notes'),
            'feed_inventory_id' => $feedInventoryId !== null && $feedInventoryId !== ''
                ? (int) $feedInventoryId
                : null,
        ];
    }

    /**
     * Map normalized input to FlockDailyRecord columns.
     */
    protected function buildDailyRecordPayload(int $farmId, Flock $flock, array $normalized, Request $request, bool $isUpdate = false): array
    {
        $additionalData = $request->input('additional_data', []);
        if (!is_array($additionalData)) {
            $additionalData = [];
        }

        foreach (['light_hours', 'min_temperature', 'max_temperature', 'eggs_broken'] as $key) {
            if ($normalized[$key] !== null) {
                $additionalData[$key] = $normalized[$key];
            }
        }

        foreach (['min_weight_grams', 'max_weight_grams', 'sample_size'] as $key) {
            if (($normalized[$key] ?? 0) > 0) {
                $additionalData[$key] = $normalized[$key];
            }
        }

        $arrivalDate = Carbon::parse($flock->arrival_date);
        $recordDate = Carbon::parse($normalized['date']);
        $ageDays = ($flock->arrival_age_days ?? 0) + $arrivalDate->diffInDays($recordDate);

        $avgWeightKg = $normalized['avg_weight_grams'] > 0
            ? round($normalized['avg_weight_grams'] / 1000, 2)
            : null;

        $payload = [
            'flock_id' => $flock->id,
            'farm_id' => $farmId,
            'date' => $normalized['date'],
            'age_days' => $ageDays,
            'total_birds' => $flock->quantity ?? 0,
            // Canonical columns
            'mortality_count' => $normalized['mortality'],
            'culling_count' => $normalized['culls'],
            'average_weight_kg' => $avgWeightKg,
            'feed_consumption_kg' => $normalized['feed_kg'] > 0 ? $normalized['feed_kg'] : null,
            'water_consumption_liters' => $normalized['water_l'] > 0 ? $normalized['water_l'] : null,
            'egg_production_count' => $normalized['eggs_collected'] > 0 ? $normalized['eggs_collected'] : null,
            'egg_weight_grams' => $normalized['egg_weight_grams'] > 0 ? $normalized['egg_weight_grams'] : null,
            'temperature_celsius' => $normalized['temperature'],
            'humidity_percentage' => $normalized['humidity'],
            // Legacy columns still consumed by the frontend
            'mortality' => $normalized['mortality'],
            'culls' => $normalized['culls'],
            'feed_consumed_kg' => $normalized['feed_kg'],
            'water_consumed_liters' => $normalized['water_l'],
            'avg_weight_grams' => $normalized['avg_weight_grams'] > 0 ? $normalized['avg_weight_grams'] : null,
            'min_temperature' => $normalized['min_temperature'],
            'max_temperature' => $normalized['max_temperature'],
            'humidity' => $normalized['humidity'],
            'light_hours' => $normalized['light_hours'],
            'eggs_collected' => $normalized['eggs_collected'],
            'eggs_broken' => $normalized['eggs_broken'],
            'notes' => $normalized['notes'],
            'additional_data' => !empty($additionalData) ? $additionalData : null,
        ];

        if (!$isUpdate) {
            $payload['recorded_by'] = auth()->id();
        }

        return $payload;
    }

    /**
     * Map stored columns to the field names the frontend expects.
     */
    protected function formatDailyRecordForApi(FlockDailyRecord $record): array
    {
        return $record->toFrontendArray();
    }

    /**
     * Upsert a weight report when weight data is provided.
     */
    protected function syncWeightReport(int $farmId, Flock $flock, string $date, array $normalized): void
    {
        $avgGrams = (float) ($normalized['avg_weight_grams'] ?? 0);
        $minGrams = (float) ($normalized['min_weight_grams'] ?? 0);
        $maxGrams = (float) ($normalized['max_weight_grams'] ?? 0);

        if ($avgGrams <= 0 && $minGrams <= 0 && $maxGrams <= 0) {
            return;
        }

        if ($avgGrams <= 0) {
            if ($minGrams > 0 && $maxGrams > 0) {
                $avgGrams = ($minGrams + $maxGrams) / 2;
            } else {
                $avgGrams = $maxGrams > 0 ? $maxGrams : $minGrams;
            }
        }

        $avgWeightKg = round($avgGrams / 1000, 2);
        $minWeightKg = round(($minGrams > 0 ? $minGrams : $avgGrams) / 1000, 2);
        $maxWeightKg = round(($maxGrams > 0 ? $maxGrams : $avgGrams) / 1000, 2);
        $birdCount = (int) $flock->actual_quantity;
        $sampleSize = (int) ($normalized['sample_size'] ?? 0);

        PoultryFlockWeightReport::updateOrCreate(
            [
                'flock_id' => $flock->id,
                'report_date' => $date,
            ],
            [
                'farm_id' => $farmId,
                'average_weight' => $avgWeightKg,
                'min_weight' => $minWeightKg,
                'max_weight' => $maxWeightKg,
                'number_of_birds' => $birdCount,
                'sample_size' => $sampleSize,
                'notes' => self::AUTO_CREATED_NOTE,
                'recorded_by' => auth()->id(),
            ]
        );
    }

    /**
     * Upsert an egg report for layer flocks when eggs are collected.
     */
    protected function syncEggReport(int $farmId, Flock $flock, string $date, array $normalized): void
    {
        $eggsCollected = (int) ($normalized['eggs_collected'] ?? 0);
        if ($eggsCollected <= 0) {
            return;
        }

        $flock->loadMissing('poultryType');
        $typeName = strtolower(trim($flock->poultryType?->name ?? ''));
        if ($typeName === '' || $typeName === 'broiler') {
            return;
        }

        $birdCount = (int) ($flock->quantity ?? 0);
        $productionPct = $birdCount > 0
            ? round(($eggsCollected / $birdCount) * 100, 2)
            : 0;

        $eggsBroken = max(0, (int) ($normalized['eggs_broken'] ?? 0));
        if ($eggsBroken > $eggsCollected) {
            $eggsBroken = $eggsCollected;
        }

        $notes = self::AUTO_CREATED_NOTE;
        if ($eggsBroken > 0) {
            $notes .= ' Broken eggs: '.$eggsBroken.'.';
        }

        PoultryFlockEggReport::updateOrCreate(
            [
                'flock_id' => $flock->id,
                'date' => $date,
            ],
            [
                'farm_id' => $farmId,
                'eggs_collected' => $eggsCollected,
                'eggs_broken' => $eggsBroken,
                'average_egg_weight' => ($normalized['egg_weight_grams'] ?? 0) > 0
                    ? $normalized['egg_weight_grams']
                    : 0,
                'production_percentage' => $productionPct,
                'bird_count' => $birdCount,
                'notes' => $notes,
                'recorded_by' => auth()->id(),
            ]
        );
    }

    /**
     * Create feed usage via chosen inventory or FIFO when no schedule-based usage was logged.
     */
    protected function syncStandaloneFeedUsage(
        int $farmId,
        Flock $flock,
        string $date,
        float $feedKg,
        ?int $preferredInventoryId = null
    ): bool {
        if ($feedKg <= 0) {
            return false;
        }

        $alreadyLogged = PoultryFeedUsage::where('farm_id', $farmId)
            ->where('flock_id', $flock->id)
            ->whereDate('usage_date', $date)
            ->exists();

        if ($alreadyLogged) {
            return false;
        }

        $inventory = null;
        if ($preferredInventoryId) {
            $inventory = PoultryFeedInventory::where('farm_id', $farmId)
                ->where('id', $preferredInventoryId)
                ->first();
        }

        if (!$inventory) {
            $inventory = PoultryFeedInventory::where('farm_id', $farmId)
                ->where('quantity', '>', 0)
                ->whereIn('status', ['available', 'in_use'])
                ->whereHas('feedType', function ($query) use ($flock) {
                    $query->where('poultry_type_id', $flock->poultry_type_id);
                })
                ->orderBy('created_at', 'asc')
                ->first();
        }

        if (!$inventory) {
            $inventory = PoultryFeedInventory::where('farm_id', $farmId)
                ->whereIn('status', ['available', 'in_use', 'depleted'])
                ->whereHas('feedType', function ($query) use ($flock) {
                    $query->where('poultry_type_id', $flock->poultry_type_id);
                })
                ->orderBy('created_at', 'asc')
                ->first();
        }

        if (!$inventory) {
            $inventory = PoultryFeedInventory::where('farm_id', $farmId)
                ->whereIn('status', ['available', 'in_use', 'depleted'])
                ->orderBy('created_at', 'asc')
                ->first();
        }

        if (!$inventory) {
            $feedTypeId = \App\Models\PoultryFeedType::where('poultry_type_id', $flock->poultry_type_id)
                ->orderBy('id')
                ->value('id');

            if (!$feedTypeId) {
                $feedTypeId = \App\Models\PoultryFeedType::where('farm_id', $farmId)
                    ->orderBy('id')
                    ->value('id');
            }

            if (!$feedTypeId) {
                return false;
            }

            $inventory = FeedUsageInventoryService::resolveOrCreateInventory(
                $farmId,
                (int) $feedTypeId,
                auth()->id(),
                $preferredInventoryId
            );
        }

        $deductAmount = $feedKg;
        FeedUsageInventoryService::deductFromInventory($inventory, $deductAmount);

        $usage = PoultryFeedUsage::create([
            'farm_id' => $farmId,
            'poultry_feed_inventory_id' => $inventory->id,
            'poultry_feed_type_id' => $inventory->poultry_feed_type_id,
            'flock_id' => $flock->id,
            'quantity' => $deductAmount,
            'unit_cost' => $inventory->unit_cost ?? 0,
            'usage_date' => $date,
            'created_by' => auth()->id(),
        ]);

        FlockExpenditure::recordFromFeedUsage($usage);

        return true;
    }

    /**
     * Update existing feed usage quantity or create one when editing a daily record.
     */
    protected function syncFeedUsageQuantity(
        int $farmId,
        Flock $flock,
        string $date,
        float $feedKg,
        ?int $preferredInventoryId = null
    ): void {
        if ($feedKg <= 0) {
            return;
        }

        $usage = PoultryFeedUsage::where('farm_id', $farmId)
            ->where('flock_id', $flock->id)
            ->whereDate('usage_date', $date)
            ->first();

        if ($usage) {
            $this->updateFeedUsageQuantity($usage, $feedKg, $preferredInventoryId);
            return;
        }

        $this->syncStandaloneFeedUsage($farmId, $flock, $date, $feedKg, $preferredInventoryId);
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
     * Returns true when a PoultryFeedUsage record was created or updated.
     */
    protected function updateFeedingBatchSchedules(
        $flockId,
        $date,
        $feedConsumptionKg,
        $record,
        $flock,
        ?int $preferredInventoryId = null
    ): bool {
        $feedingBatchSchedule = FeedingBatchSchedule::where('flock_id', $flockId)
            ->with(['schedule.items'])
            ->first();

        if (!$feedingBatchSchedule || !$feedingBatchSchedule->schedule) {
            return false;
        }

        $arrivalDate = Carbon::parse($flock->arrival_date)->startOfDay();
        $expectedEndDate = $flock->expected_end_date ? Carbon::parse($flock->expected_end_date)->startOfDay() : null;
        $recordDate = Carbon::parse($date)->startOfDay();

        $isInRange = $recordDate->gte($arrivalDate) && (!$expectedEndDate || $recordDate->lte($expectedEndDate));

        if (!$isInRange) {
            $schedule = $feedingBatchSchedule->schedule;
            $rangeEnd = $expectedEndDate ? $expectedEndDate->toDateString() : 'open-ended';
            $note = "Note: The date {$date} does not fall within the feeding schedule \"{$schedule->title}\" range for this flock ({$arrivalDate->toDateString()} to {$rangeEnd}).";
            $existingNotes = $record->notes;
            $record->update([
                'notes' => $existingNotes ? $existingNotes . "\n" . $note : $note,
            ]);
            return false;
        }

        $feedingDay = FeedingDayService::feedingDayForDate($flock, $date);

        $schedule = $feedingBatchSchedule->schedule;
        $scheduleItem = app(FeedingScheduleRangeService::class)->resolveForDay($schedule, $feedingDay);

        if (!$scheduleItem) {
            return false;
        }

        $feedKg = (float) $feedConsumptionKg;
        $actualQuantity = FeedingDayService::perBirdGramsFromTotalKg($feedKg, $flock);

        $batchItemPayload = [
            'feeding_schedule_item_id' => $scheduleItem->id,
            'actual_feeding_time' => $scheduleItem->feeding_times,
            'actual_quantity' => $actualQuantity,
            'actual_total_kg' => $feedKg > 0 ? round($feedKg, 3) : null,
            'status' => 'completed',
        ];

        $existingItem = FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $feedingBatchSchedule->id)
            ->whereDate('feeding_date', $date)
            ->first();

        if ($existingItem) {
            $existingItem->update($batchItemPayload);
        } else {
            FeedingBatchScheduleItem::create(array_merge($batchItemPayload, [
                'feeding_batch_schedule_id' => $feedingBatchSchedule->id,
                'feeding_date' => $date,
            ]));
        }

        $farmId = $flock->farm_id;
        $feedTypeId = $scheduleItem->feed_type_id;

        if ($feedKg <= 0 || !$feedTypeId || !$farmId) {
            return false;
        }

        $existingUsage = PoultryFeedUsage::where('farm_id', $farmId)
            ->where('flock_id', $flock->id)
            ->whereDate('usage_date', $date)
            ->first();

        if ($existingUsage) {
            // Only honour preferred inventory when its feed type matches the schedule item.
            $inventoryIdForUpdate = null;
            if ($preferredInventoryId) {
                $preferred = PoultryFeedInventory::where('farm_id', $farmId)
                    ->where('id', $preferredInventoryId)
                    ->where('poultry_feed_type_id', $feedTypeId)
                    ->first();
                if ($preferred) {
                    $inventoryIdForUpdate = $preferred->id;
                }
            }
            $this->updateFeedUsageQuantity($existingUsage, $feedKg, $inventoryIdForUpdate);
            return true;
        }

        $inventory = null;
        if ($preferredInventoryId) {
            $inventory = PoultryFeedInventory::where('farm_id', $farmId)
                ->where('id', $preferredInventoryId)
                ->where('poultry_feed_type_id', $feedTypeId)
                ->first();
        }

        if (!$inventory) {
            $inventory = PoultryFeedInventory::where('farm_id', $farmId)
                ->where('poultry_feed_type_id', $feedTypeId)
                ->where('quantity', '>', 0)
                ->whereIn('status', ['available', 'in_use'])
                ->orderBy('created_at', 'asc')
                ->first();
        }

        if (!$inventory) {
            $inventory = PoultryFeedInventory::where('farm_id', $farmId)
                ->where('poultry_feed_type_id', $feedTypeId)
                ->whereIn('status', ['available', 'in_use', 'depleted'])
                ->orderBy('created_at', 'asc')
                ->first();
        }

        if (!$inventory) {
            $inventory = PoultryFeedInventory::where('farm_id', $farmId)
                ->whereIn('status', ['available', 'in_use', 'depleted'])
                ->orderBy('created_at', 'asc')
                ->first();
        }

        if (!$inventory) {
            return false;
        }

        $deductAmount = $feedKg;
        FeedUsageInventoryService::deductFromInventory($inventory, $deductAmount);

        $usage = PoultryFeedUsage::create([
            'farm_id' => $farmId,
            'poultry_feed_inventory_id' => $inventory->id,
            'poultry_feed_type_id' => $feedTypeId,
            'flock_id' => $flock->id,
            'quantity' => $deductAmount,
            'unit_cost' => $inventory->unit_cost ?? 0,
            'usage_date' => $date,
            'created_by' => auth()->id(),
        ]);

        FlockExpenditure::recordFromFeedUsage($usage);

        return true;
    }

    /**
     * Sync mortality records for the flock on the given date.
     */
    protected function syncMortalityRecords($farmId, $flock, $date, $requestedMortality)
    {
        $requestedMortality = (int) $requestedMortality;

        $existingRecords = PoultryMortalityReport::where('farm_id', $farmId)
            ->where('flock_id', $flock->id)
            ->whereDate('date', $date)
            ->get();

        $existingTotal = (int) $existingRecords->sum('mortality_count');

        if ($requestedMortality === $existingTotal) {
            return;
        }

        if ($requestedMortality === 0) {
            PoultryMortalityReport::where('farm_id', $farmId)
                ->where('flock_id', $flock->id)
                ->whereDate('date', $date)
                ->delete();
            return;
        }

        if ($requestedMortality < $existingTotal) {
            $difference = $existingTotal - $requestedMortality;

            foreach ($existingRecords as $report) {
                if ($difference <= 0) {
                    break;
                }

                if ($report->mortality_count <= $difference) {
                    $difference -= $report->mortality_count;
                    $report->delete();
                } else {
                    $newCount = $report->mortality_count - $difference;
                    $birdCount = $flock->birdCountOnDate($date);
                    $report->update([
                        'mortality_count' => $newCount,
                        'bird_count' => $birdCount,
                        'mortality_percentage' => $birdCount > 0 ? round(($newCount / $birdCount) * 100, 2) : 0,
                    ]);
                    $difference = 0;
                }
            }
            return;
        }

        $difference = $requestedMortality - $existingTotal;
        $birdCount = $flock->birdCountOnDate($date);

        PoultryMortalityReport::create([
            'flock_id' => $flock->id,
            'farm_id' => $farmId,
            'poultry_type_id' => $flock->poultry_type_id,
            'date' => $date,
            'mortality_count' => $difference,
            'bird_count' => $birdCount,
            'mortality_percentage' => $birdCount > 0 ? round(($difference / $birdCount) * 100, 2) : 0,
            'notes' => self::AUTO_CREATED_NOTE,
            'recorded_by' => auth()->user()->id,
        ]);
    }
}
