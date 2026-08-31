<?php

namespace App\Services;

use App\Models\FarmFeedTypeAgeRange;
use App\Models\PoultryFeedType;
use Illuminate\Support\Collection;

class FeedAgeRangeService
{
    /**
     * Attach effective_start_age / effective_end_age / has_farm_override to feed types for a farm.
     * Override rows win; otherwise the feed type's own start_age/end_age are used.
     *
     * @param  Collection<int, PoultryFeedType>  $feedTypes
     * @return Collection<int, PoultryFeedType>
     */
    public function attachEffectiveRanges(Collection $feedTypes, int $farmId): Collection
    {
        if ($feedTypes->isEmpty()) {
            return $feedTypes;
        }

        $overrides = FarmFeedTypeAgeRange::where('farm_id', $farmId)
            ->whereIn('poultry_feed_type_id', $feedTypes->pluck('id'))
            ->get()
            ->keyBy('poultry_feed_type_id');

        return $feedTypes->map(function (PoultryFeedType $feedType) use ($overrides) {
            $override = $overrides->get($feedType->id);
            $hasOverride = $override !== null;

            $feedType->setAttribute(
                'effective_start_age',
                $hasOverride ? $override->start_age : $feedType->start_age
            );
            $feedType->setAttribute(
                'effective_end_age',
                $hasOverride ? $override->end_age : $feedType->end_age
            );
            $feedType->setAttribute('has_farm_override', $hasOverride);
            $feedType->setAttribute('default_start_age', $feedType->start_age);
            $feedType->setAttribute('default_end_age', $feedType->end_age);

            return $feedType;
        });
    }

    /**
     * Whether a feed type's effective age range includes $ageDays for the farm.
     * Types with no effective start_age are treated as always appropriate.
     */
    public function isFeedTypeAgeAppropriate(int $feedTypeId, int $farmId, int $ageDays): bool
    {
        $feedType = PoultryFeedType::find($feedTypeId);
        if (!$feedType) {
            return false;
        }

        $enriched = $this->attachEffectiveRanges(collect([$feedType]), $farmId)->first();
        $start = $enriched->effective_start_age;
        $end = $enriched->effective_end_age;

        if ($start === null) {
            return true;
        }

        $age = max(1, $ageDays);

        if ($age < (int) $start) {
            return false;
        }

        if ($end !== null && $age > (int) $end) {
            return false;
        }

        return true;
    }

    /**
     * Format an age range for display, e.g. "1-14" or "127+".
     */
    public static function formatRange(?int $start, ?int $end): ?string
    {
        if ($start === null) {
            return null;
        }

        if ($end === null) {
            return "{$start}+";
        }

        return "{$start}-{$end}";
    }
}
