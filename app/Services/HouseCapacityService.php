<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockHouseAllocation;
use App\Models\PoultryHouse;
use App\Models\PoultryHouseCapacityRule;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class HouseCapacityService
{
    public function flockAgeDays(Flock $flock, ?Carbon $today = null): int
    {
        $today = $today ?: Carbon::today();
        $arrival = Carbon::parse($flock->arrival_date);
        // Capacity/stage logic should never produce a negative "days since arrival".
        // If `arrival_date` is in the future, clamp the diff to 0 so age stays at `arrival_age_days`.
        $daysSinceArrival = max(0, (int) $today->diffInDays($arrival));
        return $daysSinceArrival + (int) ($flock->arrival_age_days ?? 0);
    }

    public function capacityForHouseAtAge(PoultryHouse $house, int $ageDays): int
    {
        $rule = $this->capacityRuleForHouseAtAge($house, $ageDays);
        if ($rule) return (int) $rule->capacity;

        return (int) ($house->capacity ?? 0);
    }

    public function capacityRuleForHouseAtAge(PoultryHouse $house, int $ageDays): ?PoultryHouseCapacityRule
    {
        // Open-ended rules have `max_age_days = null` and should match ages >= min_age_days.
        $rules = PoultryHouseCapacityRule::where('house_id', $house->id)
            ->where('min_age_days', '<=', $ageDays)
            ->where(function ($q) use ($ageDays) {
                $q->whereNull('max_age_days')
                    ->orWhere('max_age_days', '>=', $ageDays);
            })
            ->get();

        if ($rules->isEmpty()) return null;

        // Deterministic selection if multiple rules match (overlap shouldn't happen after validation).
        // Pick the "most specific" range: smallest interval width; for open-ended, width is infinity.
        $selected = $rules->values()->sort(function ($a, $b) {
            $aMin = (int) $a->min_age_days;
            $bMin = (int) $b->min_age_days;

            $aWidth = $a->max_age_days === null ? PHP_INT_MAX : ((int) $a->max_age_days - $aMin);
            $bWidth = $b->max_age_days === null ? PHP_INT_MAX : ((int) $b->max_age_days - $bMin);

            if ($aWidth === $bWidth) {
                // Tie-breaker: higher min_age_days is more specific.
                return $bMin <=> $aMin;
            }

            return $aWidth <=> $bWidth;
        })->first();

        return $selected ?: null;
    }

    public function formatAgeBand(?PoultryHouseCapacityRule $rule): ?string
    {
        if (!$rule) return null;
        $minW = max(0, (int) floor(((int) $rule->min_age_days) / 7));

        // Open-ended rule: e.g. "Week 8+"
        if ($rule->max_age_days === null) {
            return "Week {$minW}+";
        }

        $maxW = max(0, (int) ceil(((int) $rule->max_age_days) / 7));
        // Make it human-friendly: if same week, show "Week X", else "Weeks X–Y"
        if ($minW === $maxW) return "Week {$minW}";

        return "Weeks {$minW}–{$maxW}";
    }

    /**
     * Current occupancy by house from allocations, for active flocks only.
     */
    public function currentOccupancyForHouse(int $farmId, int $houseId): int
    {
        return (int) FlockHouseAllocation::query()
            ->join('flocks', 'flocks.id', '=', 'flock_house_allocations.flock_id')
            ->where('flock_house_allocations.farm_id', $farmId)
            ->where('flock_house_allocations.house_id', $houseId)
            ->where('flocks.status', 'active')
            ->sum('flock_house_allocations.quantity');
    }
}

