<?php

namespace App\Services;

use App\Models\BatchSchedule;
use App\Services\MedVacBatchScheduleItemGenerator;
use App\Models\BatchScheduleItem;
use App\Models\Farm;
use App\Models\FarmTaskInstance;
use App\Models\FeedingBatchScheduleItem;
use App\Models\FeedingBatchSchedule;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockSale;
use App\Models\FlockTransfer;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryFlockWeightReport;
use App\Models\PoultryMedication;
use App\Models\PoultryMedicationRecord;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryVaccinationRecord;
use App\Models\ScheduleItem;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FlockActivityReportService
{
    public const CATEGORIES = [
        'feeding',
        'feed_consumption',
        'medication',
        'deworming',
        'vaccination',
        'mortality',
        'weighing',
        'egg_production',
        'water_consumption',
        'transfer',
        'sale',
        'task',
        'daily_record',
    ];

    private const DEWORMER_KEYWORDS = [
        'dewormer',
        'de-wormer',
        'deworming',
        'anthelmintic',
        'wormer',
    ];

    /**
     * @return array{batch: array, date_range: array, summary: array, activities: LengthAwarePaginator}
     */
    public function report(
        Farm $farm,
        Flock $flock,
        string $startDate,
        string $endDate,
        ?string $activityType = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 25
    ): array {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        $rows = $this->collectActivities($farm, $flock, $start, $end);
        $rows = $this->applyFilters($rows, $activityType, $search);
        $summary = $this->buildSummary($rows);

        $sorted = $rows->sortBy([
            ['date', 'desc'],
            ['activity', 'asc'],
        ])->values();

        $paginator = $this->paginate($sorted, $page, $perPage);

        return [
            'batch' => $this->buildBatchMeta($flock, $end),
            'date_range' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'label' => $this->formatDateRangeLabel($start, $end),
            ],
            'farm_name' => $farm->name,
            'generated_at' => now()->toIso8601String(),
            'summary' => $summary,
            'activities' => $paginator,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collectActivities(Farm $farm, Flock $flock, Carbon $start, Carbon $end): Collection
    {
        $flockId = (int) $flock->id;
        $farmId = (int) $farm->id;

        $rows = collect();

        $feedingDates = $this->collectFeeding($rows, $flockId, $start, $end);
        $this->collectPlannedFeeding($rows, $flock, $start, $end, $feedingDates);
        $this->collectFeedConsumption($rows, $flockId, $farmId, $start, $end, $feedingDates);
        $batchMedVacKeys = $this->collectBatchScheduleItems($rows, $flockId, $farmId, $start, $end);
        $this->collectPlannedMedVac($rows, $flock, $start, $end);
        $this->collectMedicationRecords($rows, $flockId, $farmId, $start, $end, $batchMedVacKeys);
        $this->collectVaccinationRecords($rows, $flockId, $farmId, $start, $end, $batchMedVacKeys);
        $this->collectMortality($rows, $flockId, $farmId, $start, $end);
        $this->collectWeighing($rows, $flockId, $farmId, $start, $end);
        $this->collectEggs($rows, $flockId, $farmId, $start, $end);
        $this->collectWaterAndEnvironmental($rows, $flockId, $farmId, $start, $end);
        $this->collectTransfers($rows, $flockId, $farmId, $start, $end);
        $this->collectSales($rows, $flockId, $farmId, $start, $end);
        $this->collectTasks($rows, $flockId, $farmId, $start, $end);

        return $rows->keyBy('id')->values();
    }

    /**
     * @return array<string, true> dates (Y-m-d) that have feeding batch items
     */
    private function collectFeeding(Collection $rows, int $flockId, Carbon $start, Carbon $end): array
    {
        $feedingDates = [];

        $items = FeedingBatchScheduleItem::query()
            ->whereHas('batchSchedule', fn ($q) => $q->where('flock_id', $flockId));
        $this->applyDateRange($items, 'feeding_date', $start, $end);
        $items = $items
            ->with(['scheduleItem.feedType', 'batchSchedule'])
            ->get();

        foreach ($items as $item) {
            $date = $this->normalizeDate($item->feeding_date);
            $feedingDates[$date] = true;

            $feedName = $item->scheduleItem?->feedType?->name ?? 'Feed';
            $times = $this->normalizeFeedingTimes($item->actual_feeding_time);
            $status = $item->status ?? 'completed';

            if (empty($times)) {
                $rows->push($this->row(
                    "feeding_batch_item:{$item->id}",
                    $date,
                    'Feeding',
                    'feeding',
                    $feedName . ($item->actual_total_kg ? ' · ' . $item->actual_total_kg . ' kg' : ''),
                    $item->actual_total_kg ? (float) $item->actual_total_kg : null,
                    $item->actual_total_kg ? 'kg' : null,
                    null,
                    $status,
                    'feeding_batch_schedule_item',
                    $item->id
                ));
                continue;
            }

            foreach ($times as $index => $slot) {
                $time = is_array($slot) ? ($slot['time'] ?? null) : null;
                $label = $time ? "Feeding ({$time})" : 'Feeding';
                $desc = $feedName;
                if (is_array($slot) && isset($slot['percentage'])) {
                    $desc .= ' · ' . $slot['percentage'] . '%';
                }

                $rows->push($this->row(
                    "feeding_batch_item:{$item->id}:{$index}",
                    $date,
                    $label,
                    'feeding',
                    $desc,
                    $item->actual_total_kg ? (float) $item->actual_total_kg : null,
                    $item->actual_total_kg ? 'kg' : null,
                    null,
                    $status,
                    'feeding_batch_schedule_item',
                    $item->id
                ));
            }
        }

        return $feedingDates;
    }

    /**
     * @param  array<string, true>  $feedingDates
     */
    private function collectFeedConsumption(
        Collection $rows,
        int $flockId,
        int $farmId,
        Carbon $start,
        Carbon $end,
        array $feedingDates
    ): void {
        $records = PoultryFeedUsage::query()
            ->forFlock($flockId)
            ->forFarm($farmId);
        $this->applyDateRange($records, 'usage_date', $start, $end);
        $usages = $records
            ->with(['feedType', 'creator'])
            ->get();

        foreach ($usages as $usage) {
            $date = $this->normalizeDate($usage->usage_date);
            if (isset($feedingDates[$date])) {
                continue;
            }

            $feedName = $usage->feedType?->name ?? 'Feed';
            $rows->push($this->row(
                "feed_usage:{$usage->id}",
                $date,
                'Feed consumption',
                'feed_consumption',
                $feedName,
                (float) $usage->quantity,
                'kg',
                $usage->creator?->name,
                'completed',
                'feed_usage',
                $usage->id
            ));
        }
    }

    /**
     * @return array<string, true> keys "medication:{date}:{medId}" and "vaccination:{date}:{vacId}"
     */
    private function collectBatchScheduleItems(
        Collection $rows,
        int $flockId,
        int $farmId,
        Carbon $start,
        Carbon $end
    ): array {
        $keys = [];

        $items = BatchScheduleItem::query()
            ->whereHas('batchSchedule', fn ($q) => $q
                ->where('flock_id', $flockId)
                ->where('farm_id', $farmId))
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('actual_date', [$start->toDateString(), $end->toDateString()]);
            })
            ->with(['batchSchedule.schedule', 'scheduleItem'])
            ->get();

        $medicationNames = PoultryMedication::query()
            ->where('farm_id', $farmId)
            ->pluck('name', 'id');

        foreach ($items as $item) {
            $date = $this->normalizeDate($item->actual_date ?? $item->scheduled_date);
            $scheduleType = strtolower((string) ($item->batchSchedule?->schedule?->schedule_type ?? 'medication'));
            $name = $item->scheduleItem?->name ?? ucfirst($scheduleType);
            $isVaccination = $scheduleType === 'vaccination';

            if ($isVaccination) {
                $keys["vaccination:{$date}:{$item->poultry_vaccine_product_id}"] = true;
                $category = 'vaccination';
                $activity = 'Vaccination';
            } else {
                $medName = $medicationNames[$item->poultry_medication_id] ?? $name;
                $keys["medication:{$date}:{$item->poultry_medication_id}"] = true;
                $category = $this->isDeworming($medName, $item->scheduleItem?->description) ? 'deworming' : 'medication';
                $activity = $category === 'deworming' ? 'Deworming' : 'Medication';
                $name = $medName;
            }

            $desc = $name;
            if ($item->dosage) {
                $desc .= ' · dosage ' . $item->dosage;
            }
            if ($item->notes) {
                $desc .= ' · ' . $item->notes;
            }

            $rows->push($this->row(
                "batch_schedule_item:{$item->id}",
                $date,
                $activity,
                $category,
                $desc,
                $item->quantity ? (float) $item->quantity : null,
                $item->quantity ? 'dose' : null,
                $item->administered_by,
                $item->status ?? 'completed',
                'batch_schedule_item',
                $item->id
            ));
        }

        return $keys;
    }

    /**
     * Planned medication/vaccination from batch schedule templates (not yet executed).
     */
    private function collectPlannedMedVac(Collection $rows, Flock $flock, Carbon $start, Carbon $end): void
    {
        $flockId = (int) $flock->id;
        $arrival = Carbon::parse($flock->arrival_date)->startOfDay();
        $arrivalAge = (int) ($flock->arrival_age_days ?? 0);
        $today = Carbon::today()->toDateString();

        $batchSchedules = BatchSchedule::query()
            ->with(['schedule.items', 'items'])
            ->where('flock_id', $flockId)
            ->whereHas('schedule', fn ($q) => $q->whereIn('schedule_type', ['medication', 'vaccination']))
            ->get();

        foreach ($batchSchedules as $batch) {
            if (! $batch->schedule) {
                continue;
            }

            $scheduleType = strtolower((string) ($batch->schedule->schedule_type ?? 'medication'));
            $isVaccination = $scheduleType === 'vaccination';

            $executedKeys = [];
            $recurringItemIds = [];
            foreach ($batch->schedule->items as $templateItem) {
                if ($templateItem->is_recurring) {
                    $recurringItemIds[(int) $templateItem->id] = true;
                }
            }

            foreach ($batch->items ?? [] as $executed) {
                $scheduleItemId = (int) ($executed->schedule_item_id ?? 0);
                if ($scheduleItemId <= 0) {
                    continue;
                }
                $dateKey = $this->normalizeDate($executed->actual_date ?? $executed->scheduled_date);
                $executedKeys["{$scheduleItemId}:{$dateKey}"] = true;
                if (! isset($recurringItemIds[$scheduleItemId])) {
                    $executedKeys["{$scheduleItemId}:any"] = true;
                }
            }

            $hasMaterializedScheduled = ($batch->items ?? collect())->contains(
                fn ($item) => strtolower((string) ($item->status ?? '')) === 'scheduled'
            );

            if ($hasMaterializedScheduled) {
                foreach ($batch->items ?? [] as $materialized) {
                    if (strtolower((string) ($materialized->status ?? '')) !== 'scheduled') {
                        continue;
                    }

                    $item = $batch->schedule->items->firstWhere('id', $materialized->schedule_item_id);
                    if (! $item) {
                        continue;
                    }

                    $dateStr = $this->normalizeDate($materialized->scheduled_date);
                    if ($dateStr < $start->toDateString() || $dateStr > $end->toDateString()) {
                        continue;
                    }

                    $this->pushPlannedMedVacRow($rows, $batch, $item, $dateStr, $isVaccination, $today);
                }

                continue;
            }

            $generator = app(MedVacBatchScheduleItemGenerator::class);
            $endDate = $flock->expected_end_date
                ? Carbon::parse($flock->expected_end_date)->startOfDay()
                : null;

            foreach ($batch->schedule->items as $item) {
                $dates = $generator->expandOccurrenceDates($item, $flock, $endDate);

                foreach ($dates as $scheduledDate) {
                    if ($scheduledDate->lt($start) || $scheduledDate->gt($end)) {
                        continue;
                    }

                    $dateStr = $scheduledDate->toDateString();
                    if (
                        isset($executedKeys[$item->id . ':' . $dateStr])
                        || isset($executedKeys[$item->id . ':any'])
                    ) {
                        continue;
                    }

                    $this->pushPlannedMedVacRow($rows, $batch, $item, $dateStr, $isVaccination, $today);
                }
            }
        }
    }

    private function pushPlannedMedVacRow(
        Collection $rows,
        BatchSchedule $batch,
        ScheduleItem $item,
        string $dateStr,
        bool $isVaccination,
        string $today
    ): void {
        $name = $item->name ?? ($isVaccination ? 'Vaccination' : 'Medication');
        if ($isVaccination) {
            $category = 'vaccination';
            $activity = 'Vaccination (planned)';
        } else {
            $category = $this->isDeworming($name, $item->description) ? 'deworming' : 'medication';
            $activity = $category === 'deworming' ? 'Deworming (planned)' : 'Medication (planned)';
        }

        $desc = $name;
        if ($item->dose) {
            $desc .= ' · ' . $item->dose . ' ' . ($item->dose_unit ?? '');
        }
        if ($item->description) {
            $desc .= ' · ' . $item->description;
        }

        $status = $dateStr < $today ? 'missed' : 'scheduled';

        $rows->push($this->row(
            "planned_schedule_item:{$batch->id}:{$item->id}:{$dateStr}",
            $dateStr,
            $activity,
            $category,
            trim($desc),
            $item->dose ? (float) $item->dose : null,
            $item->dose_unit,
            null,
            $status,
            'planned_schedule_item',
            (int) $item->id
        ));
    }

    /**
     * Planned feeding slots from batch feeding schedules (days without execution records).
     *
     * @param  array<string, true>  $feedingDates
     */
    private function collectPlannedFeeding(
        Collection $rows,
        Flock $flock,
        Carbon $start,
        Carbon $end,
        array $feedingDates
    ): void {
        $flockId = (int) $flock->id;
        $arrival = Carbon::parse($flock->arrival_date)->startOfDay();
        $today = Carbon::today()->toDateString();
        $quantity = max(1, (int) ($flock->quantity ?? 0));

        $batchSchedules = FeedingBatchSchedule::query()
            ->with(['schedule.items.feedType'])
            ->where('flock_id', $flockId)
            ->get();

        if ($batchSchedules->isEmpty()) {
            return;
        }

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $dateStr = $cursor->toDateString();
            if (isset($feedingDates[$dateStr])) {
                continue;
            }

            $feedingDay = (int) $arrival->diffInDays($cursor) + 1;
            if ($feedingDay < 1) {
                continue;
            }

            $status = $dateStr < $today ? 'missed' : 'scheduled';

            foreach ($batchSchedules as $batch) {
                $scheduleItems = $batch->schedule?->items ?? collect();
                foreach ($scheduleItems as $scheduleItem) {
                    if (! $scheduleItem->coversDay($feedingDay)) {
                        continue;
                    }

                    $feedName = $scheduleItem->feedType?->name ?? 'Feed';
                    $perBirdGrams = (float) ($scheduleItem->quantity ?? 0);
                    $plannedKg = $perBirdGrams > 0
                        ? round(($perBirdGrams * $quantity) / 1000, 3)
                        : null;
                    $times = $this->normalizeFeedingTimes($scheduleItem->feeding_times);

                    if (empty($times)) {
                        $rows->push($this->row(
                            "planned_feeding:{$batch->id}:{$scheduleItem->id}:{$dateStr}",
                            $dateStr,
                            'Feeding (planned)',
                            'feeding',
                            $feedName . ($plannedKg ? " · ~{$plannedKg} kg planned" : ''),
                            $plannedKg,
                            $plannedKg ? 'kg' : null,
                            null,
                            $status,
                            'planned_feeding',
                            (int) $scheduleItem->id
                        ));
                        continue;
                    }

                    foreach ($times as $index => $slot) {
                        $time = is_array($slot) ? ($slot['time'] ?? null) : null;
                        $label = $time ? "Feeding (planned {$time})" : 'Feeding (planned)';
                        $desc = $feedName;
                        if (is_array($slot) && isset($slot['percentage'])) {
                            $desc .= ' · ' . $slot['percentage'] . '%';
                        }

                        $rows->push($this->row(
                            "planned_feeding:{$batch->id}:{$scheduleItem->id}:{$dateStr}:{$index}",
                            $dateStr,
                            $label,
                            'feeding',
                            $desc,
                            $plannedKg,
                            $plannedKg ? 'kg' : null,
                            null,
                            $status,
                            'planned_feeding',
                            (int) $scheduleItem->id
                        ));
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, true>  $batchKeys
     */
    private function collectMedicationRecords(
        Collection $rows,
        int $flockId,
        int $farmId,
        Carbon $start,
        Carbon $end,
        array $batchKeys
    ): void {
        $records = PoultryMedicationRecord::query()
            ->forFlock($flockId)
            ->forFarm($farmId)
            ->dateRange($start->toDateString(), $end->toDateString())
            ->with(['medication'])
            ->get();

        foreach ($records as $record) {
            $date = $this->normalizeDate($record->date);
            $medId = (int) $record->poultry_medication_id;
            if (isset($batchKeys["medication:{$date}:{$medId}"])) {
                continue;
            }

            $medName = $record->medication?->name ?? 'Medication';
            $category = $this->isDeworming($medName, $record->notes) ? 'deworming' : 'medication';

            $rows->push($this->row(
                "medication_record:{$record->id}",
                $date,
                $category === 'deworming' ? 'Deworming' : 'Medication',
                $category,
                $medName . ($record->notes ? ' · ' . $record->notes : ''),
                $record->quantity ? (float) $record->quantity : ($record->dosage ? (float) $record->dosage : null),
                $record->dosage_unit ?? ($record->quantity ? 'dose' : null),
                $record->administered_by,
                'completed',
                'medication_record',
                $record->id
            ));
        }
    }

    /**
     * @param  array<string, true>  $batchKeys
     */
    private function collectVaccinationRecords(
        Collection $rows,
        int $flockId,
        int $farmId,
        Carbon $start,
        Carbon $end,
        array $batchKeys
    ): void {
        $records = PoultryVaccinationRecord::query()
            ->forFlock($flockId)
            ->forFarm($farmId)
            ->dateRange($start->toDateString(), $end->toDateString())
            ->with(['vaccine'])
            ->get();

        foreach ($records as $record) {
            $date = $this->normalizeDate($record->date);
            $vacId = (int) $record->poultry_vaccine_id;
            if (isset($batchKeys["vaccination:{$date}:{$vacId}"])) {
                continue;
            }

            $vacName = $record->vaccine?->name ?? 'Vaccination';

            $rows->push($this->row(
                "vaccination_record:{$record->id}",
                $date,
                'Vaccination',
                'vaccination',
                $vacName . ($record->notes ? ' · ' . $record->notes : ''),
                $record->quantity ? (float) $record->quantity : ($record->dosage ? (float) $record->dosage : null),
                $record->dosage_unit ?? ($record->quantity ? 'dose' : null),
                $record->administered_by,
                'completed',
                'vaccination_record',
                $record->id
            ));
        }
    }

    private function collectMortality(
        Collection $rows,
        int $flockId,
        int $farmId,
        Carbon $start,
        Carbon $end
    ): void {
        $reports = PoultryMortalityReport::query()
            ->forFlock($flockId)
            ->forFarm($farmId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with('creator')
            ->get();

        foreach ($reports as $report) {
            if ((int) $report->mortality_count <= 0) {
                continue;
            }

            $date = $this->normalizeDate($report->date);

            $rows->push($this->row(
                "mortality_report:{$report->id}",
                $date,
                'Mortality',
                'mortality',
                $report->notes ?? 'Bird mortality recorded',
                (int) $report->mortality_count,
                'birds',
                $report->creator?->name,
                'completed',
                'mortality_report',
                $report->id
            ));
        }
    }

    private function collectWeighing(
        Collection $rows,
        int $flockId,
        int $farmId,
        Carbon $start,
        Carbon $end
    ): void {
        $reports = PoultryFlockWeightReport::query()
            ->where('flock_id', $flockId)
            ->where('farm_id', $farmId);
        $this->applyDateRange($reports, 'report_date', $start, $end);
        $reports = $reports
            ->with('creator')
            ->get();

        foreach ($reports as $report) {
            $date = $this->normalizeDate($report->report_date);
            $avg = (float) ($report->average_weight ?? 0);

            $rows->push($this->row(
                "weight_report:{$report->id}",
                $date,
                'Weighing',
                'weighing',
                'Average weight ' . $avg . ' g' . ($report->sample_size ? " (n={$report->sample_size})" : ''),
                $avg > 0 ? $avg : null,
                $avg > 0 ? 'g' : null,
                $report->creator?->name,
                'completed',
                'weight_report',
                $report->id
            ));
        }
    }

    private function collectEggs(
        Collection $rows,
        int $flockId,
        int $farmId,
        Carbon $start,
        Carbon $end
    ): void {
        $reports = PoultryFlockEggReport::query()
            ->where('flock_id', $flockId)
            ->where('farm_id', $farmId);
        $this->applyDateRange($reports, 'date', $start, $end);
        $reports = $reports
            ->with('creator')
            ->get();

        foreach ($reports as $report) {
            $date = $this->normalizeDate($report->date);
            $eggs = (int) ($report->eggs_collected ?? 0);
            if ($eggs <= 0) {
                continue;
            }

            $rows->push($this->row(
                "egg_report:{$report->id}",
                $date,
                'Egg production',
                'egg_production',
                $report->notes ?? 'Eggs collected',
                $eggs,
                'eggs',
                $report->creator?->name,
                'completed',
                'egg_report',
                $report->id
            ));
        }
    }

    private function collectWaterAndEnvironmental(
        Collection $rows,
        int $flockId,
        int $farmId,
        Carbon $start,
        Carbon $end
    ): void {
        $records = FlockDailyRecord::query()
            ->forFlock($flockId)
            ->forFarm($farmId)
            ->dateRange($start->toDateString(), $end->toDateString())
            ->get();

        foreach ($records as $record) {
            $date = $this->normalizeDate($record->date);
            $water = (float) ($record->water_consumption_liters ?? $record->water_consumed_liters ?? 0);

            if ($water > 0) {
                $rows->push($this->row(
                    "daily_record_water:{$record->id}",
                    $date,
                    'Water consumption',
                    'water_consumption',
                    'Water consumed',
                    $water,
                    'L',
                    $record->recorded_by,
                    'completed',
                    'daily_record',
                    $record->id
                ));
            }

            $hasEnvironmental = $record->temperature_celsius !== null
                || $record->humidity_percentage !== null
                || $record->light_hours !== null
                || !empty($record->notes);

            if ($hasEnvironmental) {
                $parts = [];
                if ($record->temperature_celsius !== null) {
                    $parts[] = 'Temp ' . $record->temperature_celsius . '°C';
                }
                if ($record->humidity_percentage !== null) {
                    $parts[] = 'Humidity ' . $record->humidity_percentage . '%';
                }
                if ($record->light_hours !== null) {
                    $parts[] = 'Light ' . $record->light_hours . 'h';
                }
                if ($record->notes) {
                    $parts[] = $record->notes;
                }

                $rows->push($this->row(
                    "daily_record_env:{$record->id}",
                    $date,
                    'Daily record',
                    'daily_record',
                    implode(' · ', $parts),
                    null,
                    null,
                    $record->recorded_by,
                    'completed',
                    'daily_record',
                    $record->id
                ));
            }
        }
    }

    private function collectTransfers(
        Collection $rows,
        int $flockId,
        int $farmId,
        Carbon $start,
        Carbon $end
    ): void {
        $transfers = FlockTransfer::query()
            ->where('flock_id', $flockId)
            ->where('farm_id', $farmId);
        $this->applyDateRange($transfers, 'transfer_date', $start, $end);
        $transfers = $transfers
            ->with(['lines.fromHouse', 'lines.toHouse', 'createdBy'])
            ->get();

        foreach ($transfers as $transfer) {
            $date = $this->normalizeDate($transfer->transfer_date);
            $lineDesc = $transfer->lines->map(function ($line) {
                $from = $line->fromHouse?->name ?? 'Pen';
                $to = $line->toHouse?->name ?? 'Pen';

                return "{$line->quantity} birds: {$from} → {$to}";
            })->implode('; ');

            $totalQty = (int) $transfer->lines->sum('quantity');

            $rows->push($this->row(
                "flock_transfer:{$transfer->id}",
                $date,
                'Transfer',
                'transfer',
                $lineDesc ?: ($transfer->note ?? 'Flock transfer'),
                $totalQty > 0 ? $totalQty : null,
                $totalQty > 0 ? 'birds' : null,
                $transfer->createdBy?->name,
                'completed',
                'flock_transfer',
                $transfer->id
            ));
        }
    }

    private function collectSales(
        Collection $rows,
        int $flockId,
        int $farmId,
        Carbon $start,
        Carbon $end
    ): void {
        $sales = FlockSale::query()
            ->where('flock_id', $flockId)
            ->where('farm_id', $farmId);
        $this->applyDateRange($sales, 'date', $start, $end);
        $sales = $sales
            ->with('createdBy')
            ->get();

        foreach ($sales as $sale) {
            $date = $this->normalizeDate($sale->date);
            $desc = $sale->customer_name ? "Sold to {$sale->customer_name}" : 'Bird sale';
            if ($sale->notes) {
                $desc .= ' · ' . $sale->notes;
            }

            $rows->push($this->row(
                "flock_sale:{$sale->id}",
                $date,
                'Sale',
                'sale',
                $desc,
                (int) $sale->quantity,
                'birds',
                $sale->createdBy?->name,
                'completed',
                'flock_sale',
                $sale->id
            ));
        }
    }

    private function collectTasks(
        Collection $rows,
        int $flockId,
        int $farmId,
        Carbon $start,
        Carbon $end
    ): void {
        $instances = FarmTaskInstance::query()
            ->where('farm_id', $farmId)
            ->where('flock_id', $flockId);
        $this->applyDateRange($instances, 'scheduled_date', $start, $end);
        $instances = $instances
            ->with(['assignee', 'completion.completedByUser'])
            ->get();

        foreach ($instances as $instance) {
            $date = $this->normalizeDate($instance->scheduled_date);
            $performedBy = $instance->completion?->completedByUser?->name
                ?? $instance->assignee?->name;

            $rows->push($this->row(
                "farm_task_instance:{$instance->id}",
                $date,
                $instance->title,
                'task',
                $instance->description ?? $instance->section,
                null,
                null,
                $performedBy,
                $instance->status,
                'farm_task_instance',
                $instance->id
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $id,
        string $date,
        string $activity,
        string $category,
        string $description,
        int|float|null $quantity,
        ?string $unit,
        ?string $performedBy,
        string $status,
        string $sourceType,
        int $sourceId
    ): array {
        return [
            'id' => $id,
            'date' => $date,
            'activity' => $activity,
            'category' => $category,
            'description' => $description,
            'quantity' => $quantity,
            'unit' => $unit,
            'performed_by' => $performedBy,
            'status' => $status,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    private function buildSummary(Collection $rows): array
    {
        $summary = [
            'total_activities' => $rows->count(),
        ];

        $feedConsumedKg = $rows
            ->filter(fn ($r) => $r['category'] === 'feed_consumption'
                || ($r['category'] === 'feeding' && ($r['source_type'] ?? '') !== 'planned_feeding'))
            ->sum(fn ($r) => (float) ($r['quantity'] ?? 0));
        if ($feedConsumedKg > 0) {
            $summary['feed_consumed_kg'] = round($feedConsumedKg, 2);
        }

        $feedPlannedKg = $rows
            ->filter(fn ($r) => ($r['source_type'] ?? '') === 'planned_feeding')
            ->sum(fn ($r) => (float) ($r['quantity'] ?? 0));
        if ($feedPlannedKg > 0) {
            $summary['feed_planned_kg'] = round($feedPlannedKg, 2);
        }

        $medCount = $rows->where('category', 'medication')->count();
        if ($medCount > 0) {
            $summary['medication_count'] = $medCount;
        }

        $dewormCount = $rows->where('category', 'deworming')->count();
        if ($dewormCount > 0) {
            $summary['deworming_count'] = $dewormCount;
        }

        $vacCount = $rows->where('category', 'vaccination')->count();
        if ($vacCount > 0) {
            $summary['vaccination_count'] = $vacCount;
        }

        $mortality = $rows->where('category', 'mortality')->sum(fn ($r) => (int) ($r['quantity'] ?? 0));
        if ($mortality > 0) {
            $summary['mortality_count'] = (int) $mortality;
        }

        $tasksCompleted = $rows->where('category', 'task')->where('status', 'completed')->count();
        if ($tasksCompleted > 0) {
            $summary['tasks_completed'] = $tasksCompleted;
        }

        $eggs = $rows->where('category', 'egg_production')->sum(fn ($r) => (int) ($r['quantity'] ?? 0));
        if ($eggs > 0) {
            $summary['egg_total'] = (int) $eggs;
        }

        $water = $rows->where('category', 'water_consumption')->sum(fn ($r) => (float) ($r['quantity'] ?? 0));
        if ($water > 0) {
            $summary['water_liters'] = round($water, 2);
        }

        $feedingCount = $rows->where('category', 'feeding')->count();
        if ($feedingCount > 0) {
            $summary['feeding_count'] = $feedingCount;
        }

        $transferCount = $rows->where('category', 'transfer')->count();
        if ($transferCount > 0) {
            $summary['transfer_count'] = $transferCount;
        }

        $saleBirds = $rows->where('category', 'sale')->sum(fn ($r) => (int) ($r['quantity'] ?? 0));
        if ($saleBirds > 0) {
            $summary['birds_sold'] = (int) $saleBirds;
        }

        return $summary;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function applyFilters(Collection $rows, ?string $activityType, ?string $search): Collection
    {
        if ($activityType && in_array($activityType, self::CATEGORIES, true)) {
            $rows = $rows->where('category', $activityType)->values();
        }

        if ($search !== null && trim($search) !== '') {
            $needle = strtolower(trim($search));
            $rows = $rows->filter(function (array $row) use ($needle) {
                $haystack = strtolower(implode(' ', [
                    $row['activity'] ?? '',
                    $row['description'] ?? '',
                    $row['category'] ?? '',
                    $row['performed_by'] ?? '',
                ]));

                return str_contains($haystack, $needle);
            })->values();
        }

        return $rows;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function paginate(Collection $items, int $page, int $perPage): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $total = $items->count();
        $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    private function buildBatchMeta(Flock $flock, Carbon $referenceDate): array
    {
        $flock->loadMissing('poultryType');
        $arrival = Carbon::parse($flock->arrival_date)->startOfDay();
        $ref = $referenceDate->copy()->startOfDay();
        $daysSince = max(0, $arrival->diffInDays($ref, false));
        $batchWeek = max(1, (int) floor($daysSince / 7) + 1);

        return [
            'id' => $flock->id,
            'name' => $flock->name,
            'batch_number' => $flock->batch_number,
            'poultry_type' => $flock->poultryType?->name,
            'arrival_date' => $arrival->toDateString(),
            'batch_week' => $batchWeek,
            'current_age_days' => (int) $flock->arrival_age_days + $daysSince,
        ];
    }

    private function formatDateRangeLabel(Carbon $start, Carbon $end): string
    {
        $fmt = fn (Carbon $d) => $d->format('d M Y');

        if ($start->isSameDay($end)) {
            return $fmt($start);
        }

        if ($start->year === $end->year) {
            return $start->format('d M') . ' – ' . $end->format('d M Y');
        }

        return $fmt($start) . ' – ' . $fmt($end);
    }

    private function isDeworming(?string $name, ?string $extra = null): bool
    {
        $text = strtolower(trim(($name ?? '') . ' ' . ($extra ?? '')));
        foreach (self::DEWORMER_KEYWORDS as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function applyDateRange($query, string $column, Carbon $start, Carbon $end)
    {
        return $query
            ->whereDate($column, '>=', $start->toDateString())
            ->whereDate($column, '<=', $end->toDateString());
    }

    private function normalizeDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    /**
     * @return list<mixed>
     */
    private function normalizeFeedingTimes(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
