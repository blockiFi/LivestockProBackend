<?php

namespace App\Services\EggCollection;

use App\Models\Flock;
use App\Models\PoultryFlockEggCollection;
use App\Models\PoultryFlockEggCollectionCell;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryHouseCell;
use Illuminate\Support\Collection;

/**
 * Keeps daily egg reports in step with their collection rounds.
 *
 * A report with no rounds is a plain total (how every report worked before rounds existed).
 * Once a report has rounds, its eggs_collected / eggs_broken / average_egg_weight are the
 * sum (or egg-weighted average) of its rounds.
 */
class EggCollectionService
{
    /** Find the live report for a flock and day, or create an empty one. */
    public function reportForDay(Flock $flock, string $date, int $userId): PoultryFlockEggReport
    {
        $report = PoultryFlockEggReport::where('flock_id', $flock->id)
            ->whereDate('date', $date)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($report) {
            return $report;
        }

        return PoultryFlockEggReport::create([
            'farm_id' => $flock->farm_id,
            'flock_id' => $flock->id,
            'date' => $date,
            'eggs_collected' => 0,
            'eggs_broken' => 0,
            'entry_mode' => PoultryFlockEggCollection::MODE_TOTAL,
            'average_egg_weight' => 0,
            'production_percentage' => 0,
            'bird_count' => $flock->birdCountOnDate($date),
            'recorded_by' => $userId,
        ]);
    }

    /**
     * Before the first round is added to a report that already holds a typed-in total,
     * turn that total into round 1 so the new round adds to it instead of replacing it.
     */
    public function preserveExistingTotal(PoultryFlockEggReport $report): void
    {
        if ($report->collections()->exists()) {
            return;
        }

        if ((int) $report->eggs_collected <= 0 && (int) $report->eggs_broken <= 0) {
            return;
        }

        PoultryFlockEggCollection::create([
            'egg_report_id' => $report->id,
            'round_no' => 1,
            'entry_mode' => PoultryFlockEggCollection::MODE_TOTAL,
            'source' => 'legacy',
            'eggs_collected' => (int) $report->eggs_collected,
            'eggs_broken_cells' => 0,
            'eggs_broken_extra' => (int) $report->eggs_broken,
            'average_egg_weight' => (float) $report->average_egg_weight > 0 ? $report->average_egg_weight : null,
            'recorded_by' => $report->recorded_by,
        ]);
    }

    public function nextRoundNo(PoultryFlockEggReport $report): int
    {
        return ((int) $report->collections()->max('round_no')) + 1;
    }

    /**
     * Recompute a report's totals from its rounds. Deletes the report when its last round
     * is removed and nothing else is left on it.
     */
    public function recalculate(PoultryFlockEggReport $report): ?PoultryFlockEggReport
    {
        $rounds = $report->collections()->get();

        if ($rounds->isEmpty()) {
            $report->delete();

            return null;
        }

        $eggs = (int) $rounds->sum('eggs_collected');
        $broken = (int) $rounds->sum(fn (PoultryFlockEggCollection $r) => $r->eggs_broken);

        $weighted = $rounds->filter(fn ($r) => (float) $r->average_egg_weight > 0 && $r->eggs_collected > 0);
        $weightEggs = (int) $weighted->sum('eggs_collected');
        $avgWeight = $weightEggs > 0
            ? round($weighted->sum(fn ($r) => (float) $r->average_egg_weight * $r->eggs_collected) / $weightEggs, 2)
            : 0;

        $flock = Flock::find($report->flock_id);
        $date = $report->date?->toDateString() ?? (string) $report->date;
        $birdCount = $flock ? $flock->birdCountOnDate($date) : (int) $report->bird_count;

        $report->update([
            'eggs_collected' => $eggs,
            'eggs_broken' => min($broken, $eggs),
            'entry_mode' => $rounds->contains(fn ($r) => $r->entry_mode === PoultryFlockEggCollection::MODE_CELL)
                ? PoultryFlockEggCollection::MODE_CELL
                : PoultryFlockEggCollection::MODE_TOTAL,
            'average_egg_weight' => $avgWeight,
            'bird_count' => $birdCount,
            'production_percentage' => $birdCount > 0 ? round(($eggs / $birdCount) * 100, 2) : 0,
        ]);

        return $report->fresh();
    }

    /**
     * Active cells assigned to a flock, with row and house loaded, in sheet order.
     *
     * @return Collection<int, PoultryHouseCell>
     */
    public function flockCells(Flock $flock): Collection
    {
        return PoultryHouseCell::with(['row.house:id,name,birds_per_cell'])
            ->where('flock_id', $flock->id)
            ->where('is_active', true)
            ->whereHas('row', fn ($q) => $q->where('is_active', true))
            ->get()
            ->sortBy(fn (PoultryHouseCell $c) => sprintf(
                '%08d-%05d-%s-%05d',
                $c->row->house_id,
                $c->row->sort_order,
                $c->row->code,
                $c->cell_number
            ))
            ->values();
    }

    /**
     * Eggs and broken already recorded per cell for a report, optionally leaving one round out
     * (the round being edited).
     *
     * @return array<int, array{eggs: int, broken: int}> cell_id => totals
     */
    public function cellTotalsForReport(PoultryFlockEggReport $report, ?int $exceptCollectionId = null): array
    {
        $query = PoultryFlockEggCollectionCell::query()
            ->whereHas('collection', function ($q) use ($report, $exceptCollectionId) {
                $q->where('egg_report_id', $report->id);
                if ($exceptCollectionId) {
                    $q->where('id', '!=', $exceptCollectionId);
                }
            })
            ->selectRaw('cell_id, SUM(eggs) as eggs, SUM(broken) as broken')
            ->groupBy('cell_id');

        $totals = [];
        foreach ($query->get() as $row) {
            $totals[(int) $row->cell_id] = ['eggs' => (int) $row->eggs, 'broken' => (int) $row->broken];
        }

        return $totals;
    }

    public static function cellLabel(PoultryHouseCell $cell): string
    {
        return $cell->row->code.$cell->cell_number;
    }
}
