<?php

namespace App\Services;

use App\Models\Flock;
use App\Models\FlockExpenditure;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedInventorySettlement;
use App\Models\PoultryFeedUsage;

class FeedUsageInventoryService
{
    /**
     * Deduct feed from inventory when recording new usage.
     * Inventory may go negative when usage exceeds available stock.
     */
    public static function deductFromInventory(PoultryFeedInventory $inventory, float $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $inventory->decrement('quantity', $quantity);
        $inventory->refresh();
        $inventory->updateStatusBasedOnQuantity();
    }

    /**
     * Return feed to inventory when usage is reduced or deleted.
     */
    public static function restoreToInventory(?PoultryFeedInventory $inventory, float $quantity): void
    {
        if (!$inventory || $quantity <= 0) {
            return;
        }

        $inventory->increment('quantity', $quantity);
        $inventory->refresh();
        $inventory->updateStatusBasedOnQuantity();
    }

    /**
     * Adjust inventory when an existing usage record changes quantity and/or inventory batch.
     */
    public static function applyUsageChange(
        PoultryFeedUsage $usage,
        float $newQuantity,
        ?int $newInventoryId = null
    ): void {
        $oldQuantity = (float) $usage->quantity;
        $oldInventoryId = (int) $usage->poultry_feed_inventory_id;
        $targetInventoryId = $newInventoryId ?? $oldInventoryId;

        if ($targetInventoryId !== $oldInventoryId) {
            self::restoreToInventory($usage->feedInventory, $oldQuantity);

            $newInventory = PoultryFeedInventory::findOrFail($targetInventoryId);
            self::deductFromInventory($newInventory, $newQuantity);

            return;
        }

        $difference = $newQuantity - $oldQuantity;
        if ($difference == 0.0) {
            return;
        }

        $inventory = $usage->feedInventory;
        if (!$inventory) {
            return;
        }

        if ($difference > 0) {
            self::deductFromInventory($inventory, $difference);
        } else {
            self::restoreToInventory($inventory, abs($difference));
        }
    }

    /**
     * Restore inventory when a usage record is deleted.
     */
    public static function restoreOnDelete(PoultryFeedUsage $usage): void
    {
        self::restoreToInventory($usage->feedInventory, (float) $usage->quantity);
    }

    /**
     * Use incoming stock from a newly added inventory batch to settle older
     * negative batches of the same feed type (FIFO by created_at).
     *
     * Also records the topped-up quantity against the new batch (by reassigning
     * overdraft usages and/or creating a settlement usage) so the new inventory
     * keeps a usage history that explains the stock deduction.
     *
     * @return float Remaining quantity for the new inventory after top-ups
     */
    public static function settleNegativeInventoriesFromNewStock(PoultryFeedInventory $newInventory): float
    {
        $remaining = (float) $newInventory->quantity;

        if ($remaining <= 0) {
            return $remaining;
        }

        $negativeInventories = PoultryFeedInventory::where('farm_id', $newInventory->farm_id)
            ->where('poultry_feed_type_id', $newInventory->poultry_feed_type_id)
            ->where('id', '!=', $newInventory->id)
            ->where('quantity', '<', 0)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($negativeInventories as $negativeInventory) {
            if ($remaining <= 0) {
                break;
            }

            $deficit = abs((float) $negativeInventory->quantity);
            $topUp = min($remaining, $deficit);

            $negativeInventory->increment('quantity', $topUp);
            $negativeInventory->refresh();
            $negativeInventory->updateStatusBasedOnQuantity();

            // Preserve audit trail on the new batch for every kg used to settle.
            self::recordTopUpOnNewInventory($negativeInventory, $newInventory, $topUp);

            $remaining -= $topUp;
        }

        return round($remaining, 2);
    }

    /**
     * Ensure the new inventory has usage rows totaling $amount for the top-up.
     * Prefer reassigning overdraft usages from the settled batch; create a
     * settlement usage for any shortfall so the deduction is never silent.
     */
    public static function recordTopUpOnNewInventory(
        PoultryFeedInventory $fromInventory,
        PoultryFeedInventory $toInventory,
        float $amount
    ): void {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        $recorded = self::reassignUsagesForTopUp($fromInventory, $toInventory, $amount);
        $shortfall = round($amount - $recorded, 2);

        if ($shortfall > 0.001) {
            self::createSettlementUsageWithLog($fromInventory, $toInventory, $shortfall);
        }
    }

    /**
     * Whether this inventory batch can be deleted (unused or only auto-settlement rows).
     */
    public static function canDeleteInventory(PoultryFeedInventory $inventory): bool
    {
        if ($inventory->status === 'closed') {
            return false;
        }

        if (!$inventory->feedUsages()->exists()) {
            return true;
        }

        $usageIds = $inventory->feedUsages()->pluck('id');
        $settledUsageIds = PoultryFeedInventorySettlement::where('to_inventory_id', $inventory->id)
            ->whereIn('usage_id', $usageIds)
            ->pluck('usage_id')
            ->unique()
            ->filter();

        return $settledUsageIds->count() === $usageIds->count();
    }

    /**
     * Reverse auto-settlements from batch creation, then delete the inventory.
     */
    public static function reverseSettlementsAndDelete(PoultryFeedInventory $inventory): void
    {
        if (!self::canDeleteInventory($inventory)) {
            throw new \RuntimeException('This inventory batch cannot be deleted because it has been used.');
        }

        $settlements = PoultryFeedInventorySettlement::where('to_inventory_id', $inventory->id)->get();

        foreach ($settlements as $settlement) {
            self::reverseSettlement($settlement);
        }

        $inventory->delete();
    }

    protected static function reverseSettlement(PoultryFeedInventorySettlement $settlement): void
    {
        $from = PoultryFeedInventory::find($settlement->from_inventory_id);
        $qty = round((float) $settlement->quantity, 3);
        $usage = $settlement->usage_id ? PoultryFeedUsage::find($settlement->usage_id) : null;

        if ($settlement->source_usage_id) {
            $sourceUsage = PoultryFeedUsage::find($settlement->source_usage_id);
            if ($sourceUsage) {
                $sourceUsage->update([
                    'quantity' => round((float) $sourceUsage->quantity + $qty, 3),
                ]);
                FlockExpenditure::recordFromFeedUsage($sourceUsage);
            }

            if ($usage) {
                FlockExpenditure::deleteForSource('feed_usage', $usage->id);
                $usage->delete();
            }
        } elseif ($usage) {
            FlockExpenditure::deleteForSource('feed_usage', $usage->id);
            $usage->update([
                'poultry_feed_inventory_id' => $settlement->from_inventory_id,
                'unit_cost' => $from?->unit_cost ?? $usage->unit_cost,
            ]);
            FlockExpenditure::recordFromFeedUsage($usage);
        }

        if ($from && $qty > 0) {
            $from->decrement('quantity', $qty);
            $from->refresh();
            $from->updateStatusBasedOnQuantity();
        }

        $settlement->delete();
    }

    protected static function logSettlement(
        PoultryFeedInventory $fromInventory,
        PoultryFeedInventory $toInventory,
        float $quantity,
        ?int $usageId = null,
        ?int $sourceUsageId = null
    ): void {
        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            return;
        }

        PoultryFeedInventorySettlement::create([
            'from_inventory_id' => $fromInventory->id,
            'to_inventory_id' => $toInventory->id,
            'usage_id' => $usageId,
            'source_usage_id' => $sourceUsageId,
            'quantity' => $quantity,
        ]);
    }

    /**
     *
     * @return array{usage: PoultryFeedUsage, moved_usage: ?PoultryFeedUsage}
     */
    public static function moveUsageToInventory(
        PoultryFeedUsage $usage,
        PoultryFeedInventory $destination,
        ?float $moveQuantity = null
    ): array {
        $source = $usage->feedInventory;
        if (!$source) {
            throw new \RuntimeException('Source inventory not found.');
        }

        if ((int) $destination->id === (int) $source->id) {
            throw new \RuntimeException('Destination must be different from the source inventory.');
        }

        if ((int) $destination->farm_id !== (int) $usage->farm_id) {
            throw new \RuntimeException('Destination feed inventory not found in this farm.');
        }

        if (strtolower((string) $destination->status) === 'closed') {
            throw new \RuntimeException('Cannot move usage to a closed inventory batch.');
        }

        $totalQty = round((float) $usage->quantity, 2);
        $moveQty = $moveQuantity === null ? $totalQty : round((float) $moveQuantity, 2);

        if ($moveQty <= 0) {
            throw new \RuntimeException('Move quantity must be greater than zero.');
        }

        if ($moveQty > $totalQty + 0.001) {
            throw new \RuntimeException('Move quantity cannot exceed the usage quantity.');
        }

        $unitCost = $destination->unit_cost !== null
            ? (float) $destination->unit_cost
            : (float) $usage->unit_cost;

        if ($moveQty >= $totalQty - 0.001) {
            self::restoreToInventory($source, $totalQty);
            self::deductFromInventory($destination, $totalQty);

            $usage->update([
                'poultry_feed_inventory_id' => $destination->id,
                'poultry_feed_type_id' => $destination->poultry_feed_type_id,
                'unit_cost' => $unitCost,
            ]);

            return [
                'usage' => $usage->fresh(),
                'moved_usage' => null,
            ];
        }

        self::restoreToInventory($source, $moveQty);
        self::deductFromInventory($destination, $moveQty);

        $usage->update([
            'quantity' => round($totalQty - $moveQty, 2),
        ]);

        $movedUsage = PoultryFeedUsage::create([
            'farm_id' => $usage->farm_id,
            'poultry_feed_inventory_id' => $destination->id,
            'poultry_feed_type_id' => $destination->poultry_feed_type_id,
            'flock_id' => $usage->flock_id,
            'quantity' => $moveQty,
            'unit_cost' => $unitCost,
            'usage_date' => $usage->usage_date,
            'created_by' => $usage->created_by,
        ]);

        return [
            'usage' => $usage->fresh(),
            'moved_usage' => $movedUsage,
        ];
    }

    /**
     * batch that covered the deficit. Newest usages are moved first because
     * those are what pushed the old batch negative.
     *
     * @return float Quantity successfully recorded on the destination inventory
     */
    public static function reassignUsagesForTopUp(
        PoultryFeedInventory $fromInventory,
        PoultryFeedInventory $toInventory,
        float $amount
    ): float {
        $remaining = round($amount, 2);
        $recorded = 0.0;
        if ($remaining <= 0) {
            return 0.0;
        }

        $usages = PoultryFeedUsage::where('poultry_feed_inventory_id', $fromInventory->id)
            ->orderByDesc('usage_date')
            ->orderByDesc('id')
            ->get();

        foreach ($usages as $usage) {
            if ($remaining <= 0) {
                break;
            }

            $qty = round((float) $usage->quantity, 2);
            if ($qty <= 0) {
                continue;
            }

            $unitCost = $toInventory->unit_cost !== null
                ? (float) $toInventory->unit_cost
                : (float) $usage->unit_cost;

            if ($qty <= $remaining + 0.001) {
                $usage->update([
                    'poultry_feed_inventory_id' => $toInventory->id,
                    'unit_cost' => $unitCost,
                ]);
                self::logSettlement($fromInventory, $toInventory, $qty, (int) $usage->id);
                $recorded = round($recorded + $qty, 2);
                $remaining = round($remaining - $qty, 2);
                continue;
            }

            // Split: leave the covered portion on the old batch, record the
            // topped-up portion against the new batch.
            $moveQty = $remaining;
            $movedUsage = PoultryFeedUsage::create([
                'farm_id' => $usage->farm_id,
                'poultry_feed_inventory_id' => $toInventory->id,
                'poultry_feed_type_id' => $usage->poultry_feed_type_id,
                'flock_id' => $usage->flock_id,
                'quantity' => $moveQty,
                'unit_cost' => $unitCost,
                'usage_date' => $usage->usage_date,
                'created_by' => $usage->created_by,
            ]);
            $usage->update([
                'quantity' => round($qty - $moveQty, 2),
            ]);
            self::logSettlement(
                $fromInventory,
                $toInventory,
                $moveQty,
                (int) $movedUsage->id,
                (int) $usage->id
            );
            $recorded = round($recorded + $moveQty, 2);
            $remaining = 0;
        }

        return $recorded;
    }

    /**
     * Create a settlement usage on the new batch when overdraft usages could not
     * fully cover the topped-up quantity (missing/deleted history, etc.).
     */
    public static function createSettlementUsage(
        PoultryFeedInventory $fromInventory,
        PoultryFeedInventory $toInventory,
        float $quantity
    ): ?PoultryFeedUsage {
        $quantity = round($quantity, 2);
        if ($quantity <= 0) {
            return null;
        }

        $flockId = self::resolveSettlementFlockId($fromInventory, $toInventory);
        if (!$flockId) {
            // Cannot satisfy flock_id FK — leave quantity settled but log nothing.
            return null;
        }

        $createdBy = $toInventory->created_by
            ?: $fromInventory->created_by
            ?: PoultryFeedUsage::where('poultry_feed_inventory_id', $fromInventory->id)->value('created_by');

        if (!$createdBy) {
            return null;
        }

        return PoultryFeedUsage::create([
            'farm_id' => $toInventory->farm_id,
            'poultry_feed_inventory_id' => $toInventory->id,
            'poultry_feed_type_id' => $toInventory->poultry_feed_type_id,
            'flock_id' => $flockId,
            'quantity' => $quantity,
            'unit_cost' => (float) ($toInventory->unit_cost ?? 0),
            'usage_date' => now()->toDateString(),
            'created_by' => $createdBy,
        ]);
    }

    protected static function createSettlementUsageWithLog(
        PoultryFeedInventory $fromInventory,
        PoultryFeedInventory $toInventory,
        float $quantity
    ): ?PoultryFeedUsage {
        $usage = self::createSettlementUsage($fromInventory, $toInventory, $quantity);
        if ($usage) {
            self::logSettlement($fromInventory, $toInventory, $quantity, (int) $usage->id);
        }

        return $usage;
    }

    protected static function resolveSettlementFlockId(
        PoultryFeedInventory $fromInventory,
        PoultryFeedInventory $toInventory
    ): ?int {
        $fromUsageFlock = PoultryFeedUsage::where('poultry_feed_inventory_id', $fromInventory->id)
            ->orderByDesc('usage_date')
            ->orderByDesc('id')
            ->value('flock_id');
        if ($fromUsageFlock) {
            return (int) $fromUsageFlock;
        }

        $typeUsageFlock = PoultryFeedUsage::where('farm_id', $toInventory->farm_id)
            ->where('poultry_feed_type_id', $toInventory->poultry_feed_type_id)
            ->orderByDesc('usage_date')
            ->orderByDesc('id')
            ->value('flock_id');
        if ($typeUsageFlock) {
            return (int) $typeUsageFlock;
        }

        $farmFlock = Flock::where('farm_id', $toInventory->farm_id)
            ->orderByDesc('id')
            ->value('id');

        return $farmFlock ? (int) $farmFlock : null;
    }

    /**
     * Close an inventory batch and write off remaining stock as damaged.
     */
    public static function closeInventory(
        PoultryFeedInventory $inventory,
        int $closedBy,
        ?string $notes = null,
        ?int $flockId = null
    ): PoultryFeedInventory {
        if ($inventory->status === 'closed') {
            throw new \RuntimeException('Inventory is already closed');
        }

        $remaining = (float) $inventory->quantity;

        if ($remaining <= 0) {
            throw new \RuntimeException('Cannot close inventory with no remaining stock');
        }

        $inventory->update([
            'damaged_quantity' => round($remaining, 2),
            'quantity' => 0,
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $closedBy,
            'close_notes' => $notes,
            'allocated_flock_id' => $flockId,
        ]);

        $inventory = $inventory->fresh();

        if ($flockId !== null) {
            FlockExpenditure::recordFromDamagedInventoryClose(
                $inventory,
                $flockId,
                $remaining,
                $closedBy
            );
        }

        return $inventory;
    }
}
