<?php

namespace App\Services;

use App\Models\Flock;
use App\Models\FlockHouseAllocation;
use App\Models\PoultryHouse;

class HouseStatusService
{
    public function recalculateForHouse(int $farmId, int $houseId): void
    {
        $house = PoultryHouse::query()
            ->where('farm_id', $farmId)
            ->where('id', $houseId)
            ->first();

        if (!$house) {
            return;
        }

        $capacityService = app(HouseCapacityService::class);
        $occupancy = (int) $capacityService->currentOccupancyForHouse($farmId, $houseId);

        $desiredStatus = $occupancy > 0 ? 'active' : 'empty';

        // Only manage status transitions between `active` and `empty`.
        // If the house is in a manual state (inactive/maintenance), we keep it as-is.
        if (!in_array($house->status, ['active', 'empty'], true)) {
            return;
        }

        if ($house->status !== $desiredStatus) {
            $house->status = $desiredStatus;
            $house->save();
        }
    }

    /**
     * Recalculate status for every pen this flock occupies (allocations + legacy house_id).
     * Call after the flock leaves (or re-enters) active so pens become empty/active.
     */
    public function recalculateForFlock(Flock $flock): void
    {
        $farmId = (int) $flock->farm_id;

        $houseIds = FlockHouseAllocation::query()
            ->where('farm_id', $farmId)
            ->where('flock_id', (int) $flock->id)
            ->pluck('house_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($flock->house_id) {
            $houseIds[] = (int) $flock->house_id;
            $houseIds = array_values(array_unique($houseIds));
        }

        foreach ($houseIds as $houseId) {
            $this->recalculateForHouse($farmId, $houseId);
        }
    }
}
