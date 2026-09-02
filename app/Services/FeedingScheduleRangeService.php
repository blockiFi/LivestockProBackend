<?php

namespace App\Services;

use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FeedingScheduleRangeService
{
    /**
     * Resolve the schedule item covering a placement day.
     * Narrowest covering range wins; ties break on highest start_day.
     */
    public function resolveForDay(FeedingSchedule $schedule, int $day): ?FeedingScheduleItem
    {
        $items = $schedule->relationLoaded('items')
            ? $schedule->items
            : $schedule->items()->get();

        $covering = $items
            ->filter(fn (FeedingScheduleItem $item) => $item->coversDay($day))
            ->values();

        if ($covering->isEmpty()) {
            return null;
        }

        return $covering
            ->sort(function (FeedingScheduleItem $a, FeedingScheduleItem $b) {
                $widthA = $this->rangeWidth($a);
                $widthB = $this->rangeWidth($b);

                if ($widthA !== $widthB) {
                    return $widthA <=> $widthB;
                }

                return (int) $b->start_day <=> (int) $a->start_day;
            })
            ->first();
    }

    /**
     * Resolve a schedule item for missed-feeding backfill.
     * Uses the normal range match when possible; otherwise falls back so every
     * placement day from flock arrival can be backfilled (earliest range before
     * the first start_day, previous range in gaps, last range after a closed end).
     */
    public function resolveForMissedBackfillDay(FeedingSchedule $schedule, int $day): ?FeedingScheduleItem
    {
        $resolved = $this->resolveForDay($schedule, $day);
        if ($resolved !== null) {
            return $resolved;
        }

        $items = ($schedule->relationLoaded('items')
            ? $schedule->items
            : $schedule->items()->get()
        )->sortBy([
            ['start_day', 'asc'],
            ['id', 'asc'],
        ])->values();

        if ($items->isEmpty()) {
            return null;
        }

        /** @var FeedingScheduleItem $earliest */
        $earliest = $items->first();
        if ($day < (int) $earliest->start_day) {
            return $earliest;
        }

        $previous = null;
        foreach ($items as $item) {
            $start = (int) $item->start_day;
            if ($day < $start) {
                return $previous ?? $earliest;
            }

            $end = $item->end_day !== null ? (int) $item->end_day : PHP_INT_MAX;
            if ($day <= $end) {
                return $item;
            }

            $previous = $item;
        }

        return $previous ?? $earliest;
    }

    /**
     * Validate a set of ranges for a schedule.
     *
     * @param  list<array{start_day:int,end_day:?int,id?:int|null}>  $ranges
     * @return array{errors: list<string>, warnings: list<string>, conflicts: list<array{a:int|string,b:int|string}>}
     */
    public function validateRanges(array $ranges, ?int $ignoreItemId = null): array
    {
        $errors = [];
        $warnings = [];
        $conflicts = [];

        $normalized = [];
        foreach ($ranges as $index => $range) {
            $id = $range['id'] ?? null;
            if ($ignoreItemId !== null && $id !== null && (int) $id === $ignoreItemId) {
                continue;
            }

            $start = isset($range['start_day']) ? (int) $range['start_day'] : null;
            $end = array_key_exists('end_day', $range) && $range['end_day'] !== null && $range['end_day'] !== ''
                ? (int) $range['end_day']
                : null;

            if ($start === null || $start < 1) {
                $errors[] = "Range at index {$index} must have start_day >= 1.";
                continue;
            }

            if ($end !== null && $end < $start) {
                $errors[] = "Range starting at day {$start} has end_day before start_day.";
                continue;
            }

            $normalized[] = [
                'key' => $id ?? "index:{$index}",
                'index' => $index,
                'start_day' => $start,
                'end_day' => $end,
            ];
        }

        usort($normalized, function ($a, $b) {
            if ($a['start_day'] !== $b['start_day']) {
                return $a['start_day'] <=> $b['start_day'];
            }

            $endA = $a['end_day'] ?? PHP_INT_MAX;
            $endB = $b['end_day'] ?? PHP_INT_MAX;

            return $endA <=> $endB;
        });

        $openEnded = array_values(array_filter($normalized, fn ($r) => $r['end_day'] === null));
        if (count($openEnded) > 1) {
            $errors[] = 'Only one open-ended range (null end_day) is allowed per schedule.';
            $conflicts[] = [
                'a' => $openEnded[0]['key'],
                'b' => $openEnded[1]['key'],
            ];
        }

        if (count($openEnded) === 1) {
            $open = $openEnded[0];
            $last = end($normalized);
            if ($last && $last['key'] !== $open['key']) {
                $errors[] = 'An open-ended range must be the last (highest start_day) range in the schedule.';
                $conflicts[] = [
                    'a' => $open['key'],
                    'b' => $last['key'],
                ];
            }
        }

        for ($i = 0; $i < count($normalized); $i++) {
            for ($j = $i + 1; $j < count($normalized); $j++) {
                $a = $normalized[$i];
                $b = $normalized[$j];
                if ($this->rangesOverlap($a['start_day'], $a['end_day'], $b['start_day'], $b['end_day'])) {
                    $errors[] = sprintf(
                        'Ranges overlap: Day %s%s and Day %s%s.',
                        $a['start_day'],
                        $a['end_day'] === null ? '+' : '-' . $a['end_day'],
                        $b['start_day'],
                        $b['end_day'] === null ? '+' : '-' . $b['end_day']
                    );
                    $conflicts[] = ['a' => $a['key'], 'b' => $b['key']];
                }
            }
        }

        // Gap warnings between sorted closed/open ranges.
        for ($i = 0; $i < count($normalized) - 1; $i++) {
            $current = $normalized[$i];
            $next = $normalized[$i + 1];
            if ($current['end_day'] === null) {
                continue;
            }
            $expectedNext = $current['end_day'] + 1;
            if ($next['start_day'] > $expectedNext) {
                $warnings[] = sprintf(
                    'Gap between Day %d and Day %d (days %d–%d have no feeding rate).',
                    $current['end_day'],
                    $next['start_day'],
                    $expectedNext,
                    $next['start_day'] - 1
                );
            }
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Split a range at $day: original becomes start..(day-1), new range is day..originalEnd.
     *
     * @param  array{feed_type_id?:int,quantity?:float|int|string,feeding_times?:array}  $overrides
     * @return array{0: FeedingScheduleItem, 1: FeedingScheduleItem}
     */
    public function splitAt(FeedingScheduleItem $item, int $day, array $overrides = []): array
    {
        $start = (int) $item->start_day;
        $end = $item->end_day !== null ? (int) $item->end_day : null;

        if ($day <= $start) {
            throw new InvalidArgumentException('Split day must be after the range start_day.');
        }

        if ($end !== null && $day > $end) {
            throw new InvalidArgumentException('Split day must be within the range (including open-ended).');
        }

        return DB::transaction(function () use ($item, $day, $end, $overrides) {
            $item->end_day = $day - 1;
            $item->feeding_day = $item->start_day;
            $item->save();

            $new = FeedingScheduleItem::create([
                'feeding_schedule_id' => $item->feeding_schedule_id,
                'feed_type_id' => $overrides['feed_type_id'] ?? $item->feed_type_id,
                'feeding_times' => $overrides['feeding_times'] ?? $item->feeding_times,
                'quantity' => $overrides['quantity'] ?? $item->quantity,
                'start_day' => $day,
                'end_day' => $end,
                'feeding_day' => $day,
            ]);

            return [$item->fresh(), $new];
        });
    }

    /**
     * Ordered timeline of ranges and gaps for a schedule.
     *
     * @return list<array{type: string, start_day: int, end_day: ?int, item_id?: int}>
     */
    public function timeline(FeedingSchedule $schedule): array
    {
        $items = ($schedule->relationLoaded('items')
            ? $schedule->items
            : $schedule->items()->get()
        )->sortBy([
            ['start_day', 'asc'],
            ['id', 'asc'],
        ])->values();

        $timeline = [];
        $cursor = 1;

        foreach ($items as $item) {
            $start = (int) $item->start_day;
            $end = $item->end_day !== null ? (int) $item->end_day : null;

            if ($start > $cursor) {
                $timeline[] = [
                    'type' => 'gap',
                    'start_day' => $cursor,
                    'end_day' => $start - 1,
                ];
            }

            $timeline[] = [
                'type' => 'range',
                'start_day' => $start,
                'end_day' => $end,
                'item_id' => $item->id,
            ];

            if ($end === null) {
                $cursor = PHP_INT_MAX;
            } else {
                $cursor = $end + 1;
            }
        }

        return $timeline;
    }

    /**
     * Collapse contiguous identical items within a schedule (used by AI import).
     */
    public function collapseIdenticalRuns(FeedingSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule) {
            $items = FeedingScheduleItem::where('feeding_schedule_id', $schedule->id)
                ->orderBy('start_day')
                ->orderBy('id')
                ->get();

            if ($items->count() < 2) {
                return;
            }

            /** @var FeedingScheduleItem|null $survivor */
            $survivor = null;

            foreach ($items as $item) {
                if ($survivor === null) {
                    $survivor = $item;
                    continue;
                }

                if ($this->itemsCanMerge($survivor, $item)) {
                    $survivor->end_day = $item->end_day === null || $survivor->end_day === null
                        ? null
                        : max((int) $survivor->end_day, (int) $item->end_day);
                    $survivor->feeding_day = $survivor->start_day;
                    $survivor->save();

                    DB::table('feeding_batch_schedule_items')
                        ->where('feeding_schedule_item_id', $item->id)
                        ->update(['feeding_schedule_item_id' => $survivor->id]);

                    $item->delete();
                } else {
                    $survivor = $item;
                }
            }
        });
    }

    private function rangesOverlap(int $aStart, ?int $aEnd, int $bStart, ?int $bEnd): bool
    {
        $aEndVal = $aEnd ?? PHP_INT_MAX;
        $bEndVal = $bEnd ?? PHP_INT_MAX;

        return $aStart <= $bEndVal && $bStart <= $aEndVal;
    }

    private function rangeWidth(FeedingScheduleItem $item): int
    {
        if ($item->end_day === null) {
            return PHP_INT_MAX;
        }

        return max(1, (int) $item->end_day - (int) $item->start_day + 1);
    }

    private function itemsCanMerge(FeedingScheduleItem $a, FeedingScheduleItem $b): bool
    {
        if ($a->end_day === null) {
            return false;
        }

        if ((int) $b->start_day !== ((int) $a->end_day) + 1) {
            return false;
        }

        if ((int) $a->feed_type_id !== (int) $b->feed_type_id) {
            return false;
        }

        $qtyA = (int) round(((float) $a->quantity) * 100);
        $qtyB = (int) round(((float) $b->quantity) * 100);
        if ($qtyA !== $qtyB) {
            return false;
        }

        return $this->normalizeFeedingTimes($a->feeding_times)
            === $this->normalizeFeedingTimes($b->feeding_times);
    }

    private function normalizeFeedingTimes(mixed $raw): string
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            return '[]';
        }

        $normalized = array_map(function ($entry) {
            return [
                'time' => (string) ($entry['time'] ?? ''),
                'percentage' => round((float) ($entry['percentage'] ?? 0), 2),
            ];
        }, $decoded);

        usort($normalized, fn ($x, $y) => strcmp($x['time'], $y['time']));

        return json_encode($normalized);
    }
}
