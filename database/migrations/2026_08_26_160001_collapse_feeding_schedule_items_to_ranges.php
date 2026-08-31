<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Collapse contiguous single-day feeding_schedule_items that share the same
 * feed type, quantity, and feeding_times into day ranges.
 *
 * Batch schedule items that pointed at merged rows are repointed to the
 * survivor before deletion (FK is onDelete cascade).
 *
 * down() is intentionally a no-op — collapse is one-way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $scheduleIds = DB::table('feeding_schedule_items')
                ->distinct()
                ->pluck('feeding_schedule_id');

            foreach ($scheduleIds as $scheduleId) {
                $this->collapseSchedule((int) $scheduleId);
            }
        });
    }

    public function down(): void
    {
        // Irreversible: collapsed ranges cannot be expanded without losing intent.
    }

    private function collapseSchedule(int $scheduleId): void
    {
        $items = DB::table('feeding_schedule_items')
            ->where('feeding_schedule_id', $scheduleId)
            ->orderBy('start_day')
            ->orderBy('id')
            ->get();

        if ($items->count() < 2) {
            return;
        }

        $survivor = null;
        $idsToDelete = [];

        foreach ($items as $item) {
            if ($survivor === null) {
                $survivor = $item;
                continue;
            }

            $canMerge = $this->canMerge($survivor, $item);

            if ($canMerge) {
                $newEnd = $item->end_day !== null
                    ? (int) $item->end_day
                    : null;

                // Extend survivor end (or keep null if either is open-ended).
                if ($survivor->end_day === null || $newEnd === null) {
                    DB::table('feeding_schedule_items')
                        ->where('id', $survivor->id)
                        ->update(['end_day' => null]);
                    $survivor->end_day = null;
                } else {
                    $extendedEnd = max((int) $survivor->end_day, $newEnd);
                    DB::table('feeding_schedule_items')
                        ->where('id', $survivor->id)
                        ->update(['end_day' => $extendedEnd]);
                    $survivor->end_day = $extendedEnd;
                }

                // Keep feeding_day as legacy mirror of start_day.
                DB::table('feeding_schedule_items')
                    ->where('id', $survivor->id)
                    ->update(['feeding_day' => $survivor->start_day]);

                // Repoint batch items before deleting the merged row.
                DB::table('feeding_batch_schedule_items')
                    ->where('feeding_schedule_item_id', $item->id)
                    ->update(['feeding_schedule_item_id' => $survivor->id]);

                $idsToDelete[] = $item->id;
            } else {
                $survivor = $item;
            }
        }

        if (!empty($idsToDelete)) {
            DB::table('feeding_schedule_items')
                ->whereIn('id', $idsToDelete)
                ->delete();
        }
    }

    private function canMerge(object $a, object $b): bool
    {
        // Contiguous: b starts the day after a ends (open-ended a cannot merge further).
        if ($a->end_day === null) {
            return false;
        }

        if ((int) $b->start_day !== ((int) $a->end_day) + 1) {
            return false;
        }

        if ((int) $a->feed_type_id !== (int) $b->feed_type_id) {
            return false;
        }

        // Compare quantity as scaled integers to avoid string/float drift ("40.00" vs 40).
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
};
