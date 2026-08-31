<?php

namespace App\Services;

use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockSale;
use Carbon\Carbon;

class FlockSaleCullingService
{
    public const SALE_DAILY_RECORD_NOTE = 'Auto-created for sale culling.';

    /**
     * Apply sale culls to the flock daily record for the given date.
     *
     * @return array{0: int, 1: int} [daily_record_id, culls_applied]
     */
    public static function applySaleCulling(Flock $flock, string $date, int $quantity, ?int $createdBy = null): array
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Sale quantity must be greater than zero');
        }

        $dateString = Carbon::parse($date)->toDateString();

        $dailyRecord = FlockDailyRecord::where('flock_id', $flock->id)
            ->where('farm_id', $flock->farm_id)
            ->whereDate('date', $dateString)
            ->first();

        if ($dailyRecord) {
            $newCulls = (int) $dailyRecord->culling_count + $quantity;
            $dailyRecord->update([
                'culling_count' => $newCulls,
                'culls' => $newCulls,
            ]);
            $flock->reconcileHouseAllocations();

            return [$dailyRecord->id, $quantity];
        }

        $dailyRecord = FlockDailyRecord::create([
            'flock_id' => $flock->id,
            'farm_id' => $flock->farm_id,
            'date' => $dateString,
            'total_birds' => $flock->quantity ?? 0,
            'mortality_count' => 0,
            'culling_count' => $quantity,
            'mortality' => 0,
            'culls' => $quantity,
            'feed_consumption_kg' => 0,
            'water_consumption_liters' => 0,
            'egg_production_count' => 0,
            'notes' => self::SALE_DAILY_RECORD_NOTE,
            'recorded_by' => $createdBy ?? $flock->farm?->created_by ?? 1,
        ]);

        $flock->reconcileHouseAllocations();

        return [$dailyRecord->id, $quantity];
    }

    /**
     * Reverse culls previously applied by a sale.
     */
    public static function reverseSaleCulling(FlockSale $sale): void
    {
        $cullsApplied = (int) $sale->culls_applied;
        if ($cullsApplied <= 0 || !$sale->daily_record_id) {
            return;
        }

        $dailyRecord = FlockDailyRecord::find($sale->daily_record_id);
        if (!$dailyRecord) {
            return;
        }

        $newCulls = max(0, (int) $dailyRecord->culling_count - $cullsApplied);
        $dailyRecord->update([
            'culling_count' => $newCulls,
            'culls' => $newCulls,
        ]);

        if (self::isSaleOnlyDailyRecord($dailyRecord) && self::isEmptyDailyRecord($dailyRecord->fresh())) {
            $dailyRecord->delete();
        }

        if ($sale->flock) {
            $sale->flock->reconcileHouseAllocations();
        } elseif ($dailyRecord->flock) {
            $dailyRecord->flock->reconcileHouseAllocations();
        }
    }

    /**
     * Replace culls when a sale's quantity or date changes.
     */
    public static function replaceSaleCulling(
        FlockSale $sale,
        Flock $flock,
        string $newDate,
        int $newQuantity,
        ?int $updatedBy = null
    ): array {
        self::reverseSaleCulling($sale);

        return self::applySaleCulling($flock, $newDate, $newQuantity, $updatedBy ?? $sale->created_by);
    }

    /**
     * End the batch when all live birds are sold, or reopen if birds remain after a sale reversal.
     *
     * @return bool True when the flock was auto-closed as sold.
     */
    public static function syncBatchStatusAfterSaleChange(Flock $flock, ?string $referenceDate = null): bool
    {
        $flock->refresh();

        $endDate = $referenceDate
            ? Carbon::parse($referenceDate)->toDateString()
            : now()->toDateString();

        $statusChanged = false;

        if ($flock->status === 'active' && $flock->actual_quantity === 0) {
            $flock->update([
                'status' => 'sold',
                'actual_end_date' => $endDate,
            ]);
            $statusChanged = true;
        } elseif ($flock->status === 'sold' && $flock->actual_quantity > 0) {
            $flock->update([
                'status' => 'active',
                'actual_end_date' => null,
            ]);
            $statusChanged = true;
        }

        if ($statusChanged) {
            app(HouseStatusService::class)->recalculateForFlock($flock->fresh() ?? $flock);
        }

        return $statusChanged && $flock->fresh()?->status === 'sold';
    }

    protected static function isSaleOnlyDailyRecord(FlockDailyRecord $record): bool
    {
        return str_contains((string) $record->notes, self::SALE_DAILY_RECORD_NOTE);
    }

    protected static function isEmptyDailyRecord(FlockDailyRecord $record): bool
    {
        return (int) $record->mortality_count === 0
            && (int) $record->culling_count === 0
            && (float) ($record->feed_consumption_kg ?? 0) === 0.0
            && (float) ($record->water_consumption_liters ?? 0) === 0.0
            && (float) ($record->egg_production_count ?? 0) === 0.0
            && (float) ($record->average_weight_kg ?? 0) === 0.0;
    }
}
