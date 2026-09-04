<?php

namespace App\Services;

use App\Models\FeedingBatchScheduleItem;
use App\Models\FeedingScheduleItem;
use App\Models\Flock;
use App\Models\FlockExpenditure;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedUsage;

class FeedingBatchScheduleItemService
{
    /**
     * Create a batch schedule item and deduct feed inventory when quantity is provided.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: FeedingBatchScheduleItem, 1: ?string}
     */
    public function createWithInventory(
        array $payload,
        ?int $farmId,
        ?Flock $flock,
        ?int $preferredInventoryId = null
    ): array {
        $item = FeedingBatchScheduleItem::create($payload);

        $warning = $this->recordFeedUsageForItem(
            $item,
            $farmId,
            $flock,
            (float) ($payload['actual_quantity'] ?? 0),
            $payload['feeding_date'],
            (int) $payload['feeding_schedule_item_id'],
            $preferredInventoryId
        );

        return [$item, $warning];
    }

    /**
     * Deduct inventory and log feed usage for an existing or new batch item.
     */
    public function recordFeedUsageForItem(
        FeedingBatchScheduleItem $item,
        ?int $farmId,
        ?Flock $flock,
        float $perBirdGrams,
        string $feedingDate,
        int $scheduleItemId,
        ?int $preferredInventoryId = null
    ): ?string {
        if ($perBirdGrams <= 0 || !$farmId) {
            return null;
        }

        $scheduleItem = FeedingScheduleItem::find($scheduleItemId);
        $feedTypeId = $scheduleItem?->feed_type_id;

        if (!$feedTypeId) {
            return null;
        }

        $headCount = $flock ? FeedingDayService::flockHeadCount($flock) : 1;
        $feedKg = round(($perBirdGrams * $headCount) / 1000, 3);

        if ($feedKg <= 0) {
            return null;
        }

        $inventory = FeedUsageInventoryService::resolveOrCreateInventory(
            $farmId,
            (int) $feedTypeId,
            auth()->id(),
            $preferredInventoryId
        );

        $wasAutoCreated = (float) $inventory->quantity <= 0
            && str_starts_with((string) $inventory->batch_number, 'OVERDRAFT-');

        FeedUsageInventoryService::deductFromInventory($inventory, $feedKg);

        $usage = PoultryFeedUsage::create([
            'farm_id' => $farmId,
            'poultry_feed_inventory_id' => $inventory->id,
            'poultry_feed_type_id' => $feedTypeId,
            'flock_id' => $flock?->id,
            'quantity' => $feedKg,
            'unit_cost' => $inventory->unit_cost ?? 0,
            'usage_date' => $feedingDate,
            'created_by' => auth()->id(),
        ]);

        if ($flock) {
            FlockExpenditure::recordFromFeedUsage($usage);
        }

        if ($wasAutoCreated || (float) $inventory->fresh()->quantity < 0) {
            return "{$feedingDate}: Feed deducted with zero-cost overdraft stock for feed type #{$feedTypeId} — update unit cost";
        }

        return null;
    }

    /**
     * Delete a batch item and restore feed inventory when a matching usage exists.
     */
    public function deleteWithInventoryRestore(FeedingBatchScheduleItem $item, Flock $flock): void
    {
        $feedKg = $item->actual_total_kg !== null
            ? (float) $item->actual_total_kg
            : round(((float) ($item->actual_quantity ?? 0) * FeedingDayService::flockHeadCount($flock)) / 1000, 3);

        if ($feedKg > 0) {
            $usage = PoultryFeedUsage::where('flock_id', $flock->id)
                ->whereDate('usage_date', $item->feeding_date)
                ->where('quantity', $feedKg)
                ->first();

            if (!$usage) {
                $usage = PoultryFeedUsage::where('flock_id', $flock->id)
                    ->whereDate('usage_date', $item->feeding_date)
                    ->orderByDesc('id')
                    ->first();
            }

            if ($usage) {
                FlockExpenditure::deleteForSource('feed_usage', $usage->id);
                FeedUsageInventoryService::restoreOnDelete($usage);
                $usage->delete();
            }
        }

        $item->delete();
    }

    public function resolveInventory(int $farmId, int $feedTypeId, ?int $preferredInventoryId = null): ?PoultryFeedInventory
    {
        if ($preferredInventoryId) {
            $preferred = PoultryFeedInventory::where('farm_id', $farmId)
                ->where('id', $preferredInventoryId)
                ->first();

            if ($preferred && $this->isUsableForDeduction($preferred)) {
                return $preferred;
            }
        }

        return $this->findAutoInventory($farmId, $feedTypeId);
    }

    public function isUsableForDeduction(PoultryFeedInventory $inventory): bool
    {
        return (float) $inventory->quantity > 0
            && in_array($inventory->status, ['available', 'in_use'], true);
    }

    public function hasUsableInventory(int $farmId, int $feedTypeId): bool
    {
        return PoultryFeedInventory::where('farm_id', $farmId)
            ->where('poultry_feed_type_id', $feedTypeId)
            ->where('quantity', '>', 0)
            ->whereIn('status', ['available', 'in_use'])
            ->exists();
    }

    protected function findAutoInventory(int $farmId, int $feedTypeId): ?PoultryFeedInventory
    {
        return PoultryFeedInventory::where('farm_id', $farmId)
            ->where('poultry_feed_type_id', $feedTypeId)
            ->where('quantity', '>', 0)
            ->whereIn('status', ['available', 'in_use'])
            ->orderBy('created_at', 'asc')
            ->first();
    }

    public function hasAutoInventory(int $farmId, int $feedTypeId): bool
    {
        return $this->hasUsableInventory($farmId, $feedTypeId);
    }
}
