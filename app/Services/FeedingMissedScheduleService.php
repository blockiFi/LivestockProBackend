<?php

namespace App\Services;

use App\Models\FeedingBatchSchedule;
use App\Models\FeedingBatchScheduleItem;
use App\Models\Flock;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FeedingMissedScheduleService
{
    public function __construct(
        private FeedingScheduleRangeService $rangeService,
        private FeedingBatchScheduleItemService $itemService,
        private FlockDailyRecordFeedSyncService $dailyRecordFeedSync
    ) {}

    /**
     * @param  array{from_day?: int, through_day?: int}  $options
     * @return array{
     *   missed_days: list<array{
     *     feeding_day: int,
     *     feeding_date: string,
     *     feeding_schedule_item_id: int,
     *     planned_quantity: float,
     *     feeding_times: mixed,
     *     planned_total_kg: float
     *   }>,
     *   total_feed_kg: float,
     *   count: int
     * }
     */
    public function listMissedDays(FeedingBatchSchedule $batch, Flock $flock, array $options = []): array
    {
        $missed = $this->collectMissedDays($batch, $flock, $options);
        $totalKg = array_sum(array_column($missed, 'planned_total_kg'));

        return [
            'missed_days' => $missed,
            'total_feed_kg' => round($totalKg, 3),
            'count' => count($missed),
            'inventory_requirements' => $this->buildInventoryRequirements($flock->farm_id, $missed),
        ];
    }

    /**
     * @param  array{from_day?: int, through_day?: int, status?: string}  $options
     * @return array{
     *   created_count: int,
     *   skipped_count: int,
     *   total_feed_kg: float,
     *   items: list<FeedingBatchScheduleItem>,
     *   inventory_warnings: list<string>,
     *   daily_records_created: int,
     *   daily_records_updated: int
     * }
     */
    public function implementMissed(FeedingBatchSchedule $batch, Flock $flock, array $options = []): array
    {
        $status = $options['status'] ?? 'late';
        $missed = $this->collectMissedDays($batch, $flock, $options);

        if ($missed === []) {
            return [
                'created_count' => 0,
                'skipped_count' => 0,
                'total_feed_kg' => 0,
                'items' => [],
                'inventory_warnings' => [],
                'daily_records_created' => 0,
                'daily_records_updated' => 0,
            ];
        }

        $inventoryByFeedType = $this->normalizeInventoryMap($options['inventory_by_feed_type'] ?? []);
        $requirements = $this->buildInventoryRequirements($flock->farm_id, $missed);
        $this->validateInventorySelections($flock->farm_id, $inventoryByFeedType, $requirements);
        $missingSelections = array_values(array_filter(
            $requirements,
            fn (array $req) => $req['needs_selection'] && !isset($inventoryByFeedType[$req['feed_type_id']])
        ));

        if ($missingSelections !== []) {
            $labels = array_map(
                fn (array $req) => $req['feed_type_name'] ?? "feed type #{$req['feed_type_id']}",
                $missingSelections
            );

            throw new \InvalidArgumentException(
                'Select feed inventory for: ' . implode(', ', $labels)
            );
        }

        $farmId = $flock->farm_id;
        $headCount = FeedingDayService::flockHeadCount($flock);
        $created = [];
        $warnings = [];
        $totalKg = 0.0;
        $dailyRecordsCreated = 0;
        $dailyRecordsUpdated = 0;

        DB::transaction(function () use (
            $batch,
            $flock,
            $farmId,
            $headCount,
            $status,
            $missed,
            $inventoryByFeedType,
            &$created,
            &$warnings,
            &$totalKg,
            &$dailyRecordsCreated,
            &$dailyRecordsUpdated
        ) {
            foreach ($missed as $day) {
                $perBirdGrams = (float) $day['planned_quantity'];
                $feedKg = round(($perBirdGrams * $headCount) / 1000, 3);
                $totalKg += $feedKg;

                $feedTypeId = (int) $day['feed_type_id'];
                $preferredInventoryId = $inventoryByFeedType[$feedTypeId] ?? null;

                if ($preferredInventoryId) {
                    $preferred = PoultryFeedInventory::find($preferredInventoryId);
                    if (!$preferred || !$this->itemService->isUsableForDeduction($preferred)) {
                        $preferredInventoryId = null;
                    }
                }

                [$item, $warning] = $this->itemService->createWithInventory([
                    'feeding_batch_schedule_id' => $batch->id,
                    'feeding_schedule_item_id' => $day['feeding_schedule_item_id'],
                    'actual_feeding_time' => $day['feeding_times'],
                    'actual_quantity' => $perBirdGrams,
                    'actual_total_kg' => $feedKg > 0 ? $feedKg : null,
                    'feeding_date' => $day['feeding_date'],
                    'status' => $status,
                ], $farmId, $flock, $preferredInventoryId);

                $created[] = $item;

                if ($warning) {
                    $warnings[] = $warning;
                }

                [, $wasCreated] = $this->dailyRecordFeedSync->syncFeedFromBulkBackfill(
                    $flock,
                    $day['feeding_date'],
                    $feedKg
                );

                if ($wasCreated) {
                    $dailyRecordsCreated++;
                } else {
                    $dailyRecordsUpdated++;
                }
            }
        });

        return [
            'created_count' => count($created),
            'skipped_count' => 0,
            'total_feed_kg' => round($totalKg, 3),
            'items' => $created,
            'inventory_warnings' => $warnings,
            'daily_records_created' => $dailyRecordsCreated,
            'daily_records_updated' => $dailyRecordsUpdated,
        ];
    }

    /**
     * @param  array{from_day?: int, through_day?: int}  $options
     * @return array{
     *   revertible_days: list<array{
     *     id: int,
     *     feeding_day: int,
     *     feeding_date: string,
     *     feeding_schedule_item_id: int,
     *     actual_quantity: float,
     *     planned_total_kg: float
     *   }>,
     *   total_feed_kg: float,
     *   count: int
     * }
     */
    public function listRevertibleDays(FeedingBatchSchedule $batch, Flock $flock, array $options = []): array
    {
        $revertible = $this->collectRevertibleItems($batch, $flock, $options);
        $totalKg = array_sum(array_column($revertible, 'planned_total_kg'));

        return [
            'revertible_days' => $revertible,
            'total_feed_kg' => round($totalKg, 3),
            'count' => count($revertible),
        ];
    }

    /**
     * @param  array{from_day?: int, through_day?: int}  $options
     * @return array{
     *   reverted_count: int,
     *   total_feed_kg: float,
     *   inventory_restored_kg: float
     * }
     */
    public function revertMissed(FeedingBatchSchedule $batch, Flock $flock, array $options = []): array
    {
        $revertible = $this->collectRevertibleItems($batch, $flock, $options);

        if ($revertible === []) {
            return [
                'reverted_count' => 0,
                'total_feed_kg' => 0,
                'inventory_restored_kg' => 0,
            ];
        }

        $totalKg = 0.0;
        $restoredKg = 0.0;
        $reverted = 0;

        DB::transaction(function () use ($batch, $flock, $revertible, &$totalKg, &$restoredKg, &$reverted) {
            foreach ($revertible as $day) {
                $item = FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batch->id)
                    ->where('id', $day['id'])
                    ->first();

                if (!$item || $item->status !== 'late') {
                    continue;
                }

                $feedKg = (float) $day['planned_total_kg'];
                $totalKg += $feedKg;

                $usageBefore = PoultryFeedUsage::where('flock_id', $flock->id)
                    ->whereDate('usage_date', $item->feeding_date)
                    ->exists();

                $this->itemService->deleteWithInventoryRestore($item, $flock);

                if ($usageBefore) {
                    $restoredKg += $feedKg;
                }

                $reverted++;
            }
        });

        return [
            'reverted_count' => $reverted,
            'total_feed_kg' => round($totalKg, 3),
            'inventory_restored_kg' => round($restoredKg, 3),
        ];
    }

    /**
     * @param  array{from_day?: int, through_day?: int}  $options
     * @return list<array{
     *   id: int,
     *   feeding_day: int,
     *   feeding_date: string,
     *   feeding_schedule_item_id: int,
     *   actual_quantity: float,
     *   planned_total_kg: float
     * }>
     */
    private function collectRevertibleItems(FeedingBatchSchedule $batch, Flock $flock, array $options = []): array
    {
        $today = Carbon::today()->toDateString();
        $currentFeedingDay = FeedingDayService::feedingDayForDate($flock, $today);
        $fromDay = max(1, (int) ($options['from_day'] ?? 1));
        $throughDay = min(
            $currentFeedingDay - 1,
            (int) ($options['through_day'] ?? $currentFeedingDay - 1)
        );

        if ($throughDay < $fromDay) {
            return [];
        }

        $arrival = Carbon::parse($flock->arrival_date)->startOfDay();
        $fromDate = $arrival->copy()->addDays($fromDay - 1)->toDateString();
        $throughDate = $arrival->copy()->addDays($throughDay - 1)->toDateString();

        $items = FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batch->id)
            ->where('status', 'late')
            ->whereDate('feeding_date', '>=', $fromDate)
            ->whereDate('feeding_date', '<=', $throughDate)
            ->whereDate('feeding_date', '<', $today)
            ->orderBy('feeding_date')
            ->get();

        $headCount = FeedingDayService::flockHeadCount($flock);
        $revertible = [];

        foreach ($items as $item) {
            $feedingDay = FeedingDayService::feedingDayForDate($flock, $item->feeding_date);
            $perBirdGrams = (float) ($item->actual_quantity ?? 0);
            $feedKg = $item->actual_total_kg !== null
                ? (float) $item->actual_total_kg
                : round(($perBirdGrams * $headCount) / 1000, 3);

            $revertible[] = [
                'id' => (int) $item->id,
                'feeding_day' => $feedingDay,
                'feeding_date' => Carbon::parse($item->feeding_date)->toDateString(),
                'feeding_schedule_item_id' => (int) $item->feeding_schedule_item_id,
                'actual_quantity' => $perBirdGrams,
                'planned_total_kg' => $feedKg,
            ];
        }

        return $revertible;
    }

    /**
     * @param  array{from_day?: int, through_day?: int}  $options
     * @return list<array{
     *   feeding_day: int,
     *   feeding_date: string,
     *   feeding_schedule_item_id: int,
     *   planned_quantity: float,
     *   feeding_times: mixed,
     *   planned_total_kg: float
     * }>
     */
    private function collectMissedDays(FeedingBatchSchedule $batch, Flock $flock, array $options = []): array
    {
        $batch->loadMissing('schedule.items.feedType');

        if (!$batch->schedule) {
            return [];
        }

        $arrival = Carbon::parse($flock->arrival_date)->startOfDay();
        $today = Carbon::today();
        $currentFeedingDay = FeedingDayService::feedingDayForDate($flock, $today->toDateString());

        $fromDay = max(1, (int) ($options['from_day'] ?? 1));
        $throughDay = min(
            $currentFeedingDay - 1,
            (int) ($options['through_day'] ?? $currentFeedingDay - 1)
        );

        if ($throughDay < $fromDay) {
            return [];
        }

        $existingDates = FeedingBatchScheduleItem::where('feeding_batch_schedule_id', $batch->id)
            ->pluck('feeding_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $headCount = FeedingDayService::flockHeadCount($flock);
        $missed = [];

        for ($day = $fromDay; $day <= $throughDay; $day++) {
            $scheduleItem = $this->rangeService->resolveForDay($batch->schedule, $day);

            if (!$scheduleItem) {
                continue;
            }

            $feedingDate = $arrival->copy()->addDays($day - 1)->toDateString();

            if (isset($existingDates[$feedingDate])) {
                continue;
            }

            $perBirdGrams = (float) $scheduleItem->quantity;
            $missed[] = [
                'feeding_day' => $day,
                'feeding_date' => $feedingDate,
                'feeding_schedule_item_id' => (int) $scheduleItem->id,
                'feed_type_id' => (int) $scheduleItem->feed_type_id,
                'feed_type_name' => $scheduleItem->feedType?->name ?? "Feed type #{$scheduleItem->feed_type_id}",
                'planned_quantity' => $perBirdGrams,
                'feeding_times' => $scheduleItem->feeding_times,
                'planned_total_kg' => round(($perBirdGrams * $headCount) / 1000, 3),
            ];
        }

        return $missed;
    }

    /**
     * @param  list<array{feed_type_id:int,feed_type_name?:string,planned_total_kg:float}>  $missed
     * @return list<array{
     *   feed_type_id: int,
     *   feed_type_name: string,
     *   total_feed_kg: float,
     *   missed_days_count: int,
     *   has_auto_inventory: bool,
     *   auto_inventory_id: ?int,
     *   needs_selection: bool
     * }>
     */
    private function buildInventoryRequirements(int $farmId, array $missed): array
    {
        $grouped = [];

        foreach ($missed as $day) {
            $typeId = (int) $day['feed_type_id'];
            if (!isset($grouped[$typeId])) {
                $grouped[$typeId] = [
                    'feed_type_id' => $typeId,
                    'feed_type_name' => $day['feed_type_name'] ?? "Feed type #{$typeId}",
                    'total_feed_kg' => 0.0,
                    'missed_days_count' => 0,
                ];
            }

            $grouped[$typeId]['total_feed_kg'] += (float) $day['planned_total_kg'];
            $grouped[$typeId]['missed_days_count']++;
        }

        $requirements = [];

        foreach ($grouped as $typeId => $data) {
            $autoInventory = $this->itemService->resolveInventory($farmId, (int) $typeId);
            $usableTotal = (float) PoultryFeedInventory::where('farm_id', $farmId)
                ->where('poultry_feed_type_id', (int) $typeId)
                ->where('quantity', '>', 0)
                ->whereIn('status', ['available', 'in_use'])
                ->sum('quantity');

            $requirements[] = [
                'feed_type_id' => (int) $typeId,
                'feed_type_name' => $data['feed_type_name'],
                'total_feed_kg' => round($data['total_feed_kg'], 3),
                'missed_days_count' => $data['missed_days_count'],
                'available_stock_kg' => round($usableTotal, 3),
                'has_auto_inventory' => $autoInventory !== null,
                'auto_inventory_id' => $autoInventory?->id,
                'needs_selection' => $autoInventory === null,
            ];
        }

        usort($requirements, fn ($a, $b) => $a['feed_type_id'] <=> $b['feed_type_id']);

        return $requirements;
    }

    /**
     * @param  array<int|string, int|string>  $map
     * @return array<int, int>
     */
    private function normalizeInventoryMap(array $map): array
    {
        $normalized = [];

        foreach ($map as $feedTypeId => $inventoryId) {
            if ($inventoryId === null || $inventoryId === '') {
                continue;
            }

            $normalized[(int) $feedTypeId] = (int) $inventoryId;
        }

        return $normalized;
    }

    /**
     * @param  array<int, int>  $inventoryByFeedType
     * @param  list<array{feed_type_id:int,total_feed_kg:float}>  $requirements
     */
    private function validateInventorySelections(int $farmId, array $inventoryByFeedType, array $requirements = []): void
    {
        foreach ($inventoryByFeedType as $inventoryId) {
            $inventory = PoultryFeedInventory::where('farm_id', $farmId)
                ->where('id', $inventoryId)
                ->first();

            if (!$inventory) {
                throw new \InvalidArgumentException('Selected feed inventory is not available for this farm.');
            }

            if (!$this->itemService->isUsableForDeduction($inventory)) {
                throw new \InvalidArgumentException(
                    'Selected feed inventory is fully depleted. Choose another batch with remaining stock.'
                );
            }
        }
    }
}
