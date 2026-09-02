<?php

namespace App\Services;

use App\Models\BatchSchedule;
use App\Models\BatchScheduleItem;
use App\Models\Farm;
use App\Models\FarmTaskInstance;
use App\Models\FeedingBatchSchedule;
use App\Models\Flock;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryMedicationInventory;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryVaccineInventory;
use App\Models\ScheduleItem;
use App\Services\MedVacBatchScheduleItemGenerator;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FarmAlertService
{
    public function __construct(
        private readonly FeedingMissedScheduleService $feedingMissedScheduleService
    ) {
    }

    /**
     * Farm-wide (or flock-scoped) severity-tagged alerts.
     *
     * @param  array{view_feed?: bool, view_medication?: bool, view_vaccine?: bool}  $permissions
     * @param  array{notify_low_stock?: bool, notify_schedules?: bool, notify_mortality?: bool}  $preferences
     * @return array{counts: array{critical: int, warning: int, info: int}, items: list<array<string, mixed>>}
     */
    public function forFarm(Farm $farm, ?Flock $flock = null, array $permissions = [], array $preferences = []): array
    {
        $settings = $farm->settingsOrDefault();
        $windowDays = max(0, (int) ($settings->schedule_reminder_days ?? 7));
        $lowStockEnabled = (bool) ($settings->low_stock_alerts_enabled ?? true);
        $mortalityThreshold = (float) ($settings->mortality_alert_percent ?? 2.0);

        $viewFeed = $permissions['view_feed'] ?? true;
        $viewMedication = $permissions['view_medication'] ?? true;
        $viewVaccine = $permissions['view_vaccine'] ?? true;
        $notifyLowStock = $preferences['notify_low_stock'] ?? true;
        $notifySchedules = $preferences['notify_schedules'] ?? true;
        $notifyMortality = $preferences['notify_mortality'] ?? true;

        $items = collect();

        if ($lowStockEnabled && $notifyLowStock) {
            if ($viewFeed) {
                $items = $items->merge($this->feedLowStockAlerts($farm));
                $items = $items->merge($this->feedExpiryAlerts($farm));
            }
            if ($viewMedication) {
                $items = $items->merge($this->medicationLowStockAlerts($farm));
                $items = $items->merge($this->medicationExpiryAlerts($farm));
            }
            if ($viewVaccine) {
                $items = $items->merge($this->vaccineLowStockAlerts($farm));
                $items = $items->merge($this->vaccineExpiryAlerts($farm));
            }
        }

        if ($notifySchedules) {
            $items = $items->merge($this->upcomingScheduleAlerts($farm, $flock, $windowDays));
            $items = $items->merge($this->missedFeedingAlerts($farm, $flock));
            $items = $items->merge($this->overdueTaskAlerts($farm));
        }

        if ($notifyMortality) {
            $items = $items->merge($this->mortalitySpikeAlerts($farm, $flock, $windowDays, $mortalityThreshold));
        }

        $sorted = $items
            ->sortBy(function (array $item) {
                $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];

                return ($severityOrder[$item['severity']] ?? 9) . '_' . ($item['date'] ?? '9999-99-99');
            })
            ->values();

        return [
            'counts' => [
                'critical' => $sorted->where('severity', 'critical')->count(),
                'warning' => $sorted->where('severity', 'warning')->count(),
                'info' => $sorted->where('severity', 'info')->count(),
            ],
            'items' => $sorted->all(),
            'settings' => [
                'schedule_reminder_days' => $windowDays,
                'low_stock_alerts_enabled' => $lowStockEnabled,
                'mortality_alert_percent' => $mortalityThreshold,
            ],
        ];
    }

    /**
     * Legacy per-flock notification payload shape used by FlockNotificationController.
     *
     * @return array<string, mixed>
     */
    public function forFlockLegacy(Farm $farm, Flock $flock): array
    {
        $settings = $farm->settingsOrDefault();
        $windowDays = max(0, (int) ($settings->schedule_reminder_days ?? 7));
        $lowStockEnabled = (bool) ($settings->low_stock_alerts_enabled ?? true);
        $mortalityThreshold = (float) ($settings->mortality_alert_percent ?? 2.0);

        $upcoming = $this->collectUpcomingItems($farm, $flock, $windowDays);

        $medLow = collect();
        $vacLow = collect();
        $feedLow = collect();

        if ($lowStockEnabled) {
            $medLow = PoultryMedicationInventory::with('product')
                ->where('farm_id', $farm->id)
                ->get()
                ->filter(fn (PoultryMedicationInventory $inv) => $this->isActionableLowStock(
                    (float) ($inv->quantity ?? 0),
                    $this->resolveMinStockThreshold(optional($inv->product)->min_stock_level),
                    $inv->status,
                    ['closed', 'depleted', 'expired']
                ))
                ->map(fn (PoultryMedicationInventory $inv) => [
                    'id' => $inv->id,
                    'name' => optional($inv->product)->name,
                    'quantity' => (float) ($inv->quantity ?? 0),
                    'status' => $inv->status,
                    'expiry_date' => $inv->expiry_date,
                ])
                ->values();

            $vacLow = PoultryVaccineInventory::with(['product.vaccine'])
                ->where('farm_id', $farm->id)
                ->get()
                ->filter(fn (PoultryVaccineInventory $inv) => $this->isActionableLowStock(
                    (float) ($inv->quantity ?? 0),
                    $this->resolveMinStockThreshold(optional($inv->product)->min_stock_level),
                    $inv->status,
                    ['closed', 'depleted', 'expired']
                ))
                ->map(function (PoultryVaccineInventory $inv) {
                    $vaccineName = optional(optional($inv->product)->vaccine)->name
                        ?? optional($inv->product)->name;

                    return [
                        'id' => $inv->id,
                        'name' => $vaccineName,
                        'quantity' => (float) ($inv->quantity ?? 0),
                        'status' => $inv->status,
                        'expiry_date' => $inv->expiry_date,
                    ];
                })
                ->values();

            $feedLow = PoultryFeedInventory::with(['feedType', 'feedProduct'])
                ->where('farm_id', $farm->id)
                ->get()
                ->filter(fn (PoultryFeedInventory $inv) => $this->isActionableLowStock(
                    (float) ($inv->quantity ?? 0),
                    $this->resolveMinStockThreshold(
                        optional($inv->feedProduct)->min_stock_level
                            ?? optional($inv->feedType)->min_stock_level
                    ),
                    $inv->status,
                    ['closed', 'depleted']
                ))
                ->map(function (PoultryFeedInventory $inv) {
                    $name = optional($inv->feedProduct)->name
                        ?? optional($inv->feedType)->name
                        ?? 'Feed';

                    return [
                        'id' => $inv->id,
                        'name' => $name,
                        'quantity' => (float) ($inv->quantity ?? 0),
                        'status' => $inv->status,
                    ];
                })
                ->values();
        }

        $today = Carbon::today();
        $mortalityAlerts = PoultryMortalityReport::where('farm_id', $farm->id)
            ->where('flock_id', $flock->id)
            ->whereDate('date', '>=', $today->copy()->subDays(max(1, $windowDays))->toDateString())
            ->where('mortality_percentage', '>=', $mortalityThreshold)
            ->orderByDesc('date')
            ->get()
            ->map(fn (PoultryMortalityReport $report) => [
                'id' => $report->id,
                'date' => $report->date,
                'mortality_count' => (int) $report->mortality_count,
                'bird_count' => (int) $report->bird_count,
                'mortality_percentage' => (float) $report->mortality_percentage,
                'notes' => $report->notes,
            ])
            ->values();

        return [
            'upcoming_batch_items' => $upcoming->sortBy('days_until')->values(),
            'low_stock' => [
                'medications' => $medLow,
                'vaccines' => $vacLow,
                'feeds' => $feedLow,
            ],
            'mortality_alerts' => $mortalityAlerts,
            'settings' => [
                'schedule_reminder_days' => $windowDays,
                'low_stock_alerts_enabled' => $lowStockEnabled,
                'mortality_alert_percent' => $mortalityThreshold,
            ],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function feedLowStockAlerts(Farm $farm): Collection
    {
        $inventories = PoultryFeedInventory::with(['feedType', 'feedProduct'])
            ->where('farm_id', $farm->id)
            ->get()
            ->filter(fn (PoultryFeedInventory $inv) => ! in_array(
                strtolower((string) ($inv->status ?? '')),
                ['closed', 'depleted'],
                true
            ));

        return $inventories
            ->groupBy(fn (PoultryFeedInventory $inv) => (int) ($inv->poultry_feed_type_id ?? 0))
            ->flatMap(function (Collection $group) {
                /** @var PoultryFeedInventory $first */
                $first = $group->first();
                if (! $first) {
                    return collect();
                }

                $name = optional($first->feedProduct)->name
                    ?? optional($first->feedType)->name
                    ?? 'Feed';
                $threshold = $this->resolveMinStockThreshold(
                    optional($first->feedProduct)->min_stock_level
                        ?? optional($first->feedType)->min_stock_level
                );
                $totalQty = (float) $group->sum(fn (PoultryFeedInventory $inv) => (float) ($inv->quantity ?? 0));

                if (! $this->isActionableLowStock($totalQty, $threshold, $first->status, ['closed', 'depleted'])) {
                    return collect();
                }

                return collect([
                    $this->alert(
                        'feed-low-type-' . ($first->poultry_feed_type_id ?? $first->id),
                        $totalQty <= 0 ? 'critical' : 'warning',
                        'low_stock',
                        'Low feed stock: ' . $name,
                        sprintf('%.2f kg remaining across %d batch(es)', $totalQty, $group->count()),
                        null,
                        null,
                        null,
                        '/dashboard/poultry/inventory/feeds'
                    ),
                ]);
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function medicationLowStockAlerts(Farm $farm): Collection
    {
        return PoultryMedicationInventory::with('product')
            ->where('farm_id', $farm->id)
            ->get()
            ->filter(fn (PoultryMedicationInventory $inv) => $this->isActionableLowStock(
                (float) ($inv->quantity ?? 0),
                $this->resolveMinStockThreshold(optional($inv->product)->min_stock_level),
                $inv->status,
                ['closed', 'depleted', 'expired']
            ))
            ->map(function (PoultryMedicationInventory $inv) {
                $name = optional($inv->product)->name ?? 'Medication';
                $qty = (float) ($inv->quantity ?? 0);

                return $this->alert(
                    'med-low-' . $inv->id,
                    $qty <= 0 ? 'critical' : 'warning',
                    'low_stock',
                    'Low medication stock: ' . $name,
                    sprintf('%.2f remaining (status: %s)', $qty, $inv->status ?? 'unknown'),
                    null,
                    null,
                    null,
                    '/dashboard/poultry/inventory/medications'
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function vaccineLowStockAlerts(Farm $farm): Collection
    {
        return PoultryVaccineInventory::with(['product.vaccine'])
            ->where('farm_id', $farm->id)
            ->get()
            ->filter(fn (PoultryVaccineInventory $inv) => $this->isActionableLowStock(
                (float) ($inv->quantity ?? 0),
                $this->resolveMinStockThreshold(optional($inv->product)->min_stock_level),
                $inv->status,
                ['closed', 'depleted', 'expired']
            ))
            ->map(function (PoultryVaccineInventory $inv) {
                $name = optional(optional($inv->product)->vaccine)->name
                    ?? optional($inv->product)->name
                    ?? 'Vaccine';
                $qty = (float) ($inv->quantity ?? 0);

                return $this->alert(
                    'vac-low-' . $inv->id,
                    $qty <= 0 ? 'critical' : 'warning',
                    'low_stock',
                    'Low vaccine stock: ' . $name,
                    sprintf('%.2f remaining (status: %s)', $qty, $inv->status ?? 'unknown'),
                    null,
                    null,
                    null,
                    '/dashboard/poultry/inventory/vaccination'
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function feedExpiryAlerts(Farm $farm): Collection
    {
        // Feed inventories may not always have expiry_date; skip gracefully.
        return PoultryFeedInventory::with(['feedType', 'feedProduct'])
            ->where('farm_id', $farm->id)
            ->whereNotNull('expiry_date')
            ->get()
            ->filter(fn (PoultryFeedInventory $inv) => $this->isActionableExpiryInventory(
                $inv,
                ['closed', 'depleted']
            ) && $this->expirySeverity($inv->expiry_date) !== null)
            ->map(function (PoultryFeedInventory $inv) {
                $name = optional($inv->feedProduct)->name
                    ?? optional($inv->feedType)->name
                    ?? 'Feed';
                $severity = $this->expirySeverity($inv->expiry_date);
                $date = Carbon::parse($inv->expiry_date)->toDateString();

                return $this->alert(
                    'feed-exp-' . $inv->id,
                    $severity,
                    'expiring',
                    ($severity === 'critical' ? 'Expired feed: ' : 'Expiring feed: ') . $name,
                    'Expires ' . $date,
                    $date,
                    null,
                    null,
                    '/dashboard/poultry/inventory/feeds'
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function medicationExpiryAlerts(Farm $farm): Collection
    {
        return PoultryMedicationInventory::with('product')
            ->where('farm_id', $farm->id)
            ->whereNotNull('expiry_date')
            ->get()
            ->filter(fn (PoultryMedicationInventory $inv) => $this->expirySeverity($inv->expiry_date) !== null)
            ->map(function (PoultryMedicationInventory $inv) {
                $name = optional($inv->product)->name ?? 'Medication';
                $severity = $this->expirySeverity($inv->expiry_date);
                $date = Carbon::parse($inv->expiry_date)->toDateString();

                return $this->alert(
                    'med-exp-' . $inv->id,
                    $severity,
                    'expiring',
                    ($severity === 'critical' ? 'Expired medication: ' : 'Expiring medication: ') . $name,
                    'Expires ' . $date,
                    $date,
                    null,
                    null,
                    '/dashboard/poultry/inventory/medications'
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function vaccineExpiryAlerts(Farm $farm): Collection
    {
        return PoultryVaccineInventory::with(['product.vaccine'])
            ->where('farm_id', $farm->id)
            ->whereNotNull('expiry_date')
            ->get()
            ->filter(fn (PoultryVaccineInventory $inv) => $this->expirySeverity($inv->expiry_date) !== null)
            ->map(function (PoultryVaccineInventory $inv) {
                $name = optional(optional($inv->product)->vaccine)->name
                    ?? optional($inv->product)->name
                    ?? 'Vaccine';
                $severity = $this->expirySeverity($inv->expiry_date);
                $date = Carbon::parse($inv->expiry_date)->toDateString();

                return $this->alert(
                    'vac-exp-' . $inv->id,
                    $severity,
                    'expiring',
                    ($severity === 'critical' ? 'Expired vaccine: ' : 'Expiring vaccine: ') . $name,
                    'Expires ' . $date,
                    $date,
                    null,
                    null,
                    '/dashboard/poultry/inventory/vaccination'
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function upcomingScheduleAlerts(Farm $farm, ?Flock $flock, int $windowDays): Collection
    {
        $flocks = $flock
            ? collect([$flock])
            : Flock::where('farm_id', $farm->id)->where('status', 'active')->get();

        $items = collect();
        foreach ($flocks as $f) {
            foreach ($this->collectUpcomingItems($farm, $f, $windowDays) as $row) {
                $daysUntil = (int) ($row['days_until'] ?? 0);
                $severity = $daysUntil < 0 ? 'critical' : ($daysUntil === 0 ? 'warning' : 'info');
                $dueLabel = $daysUntil < 0
                    ? 'Overdue since '
                    : ($daysUntil === 0 ? 'Due today ' : 'Due ');
                $items->push($this->alert(
                    'sched-' . $row['type'] . '-' . $row['id'] . '-' . $row['scheduled_date'],
                    $severity,
                    'upcoming_schedule',
                    ucfirst($row['type']) . ': ' . $row['title'],
                    $dueLabel . $row['scheduled_date'] . ' for ' . ($row['flock_name'] ?? $f->name),
                    $row['scheduled_date'],
                    $f->id,
                    $f->name,
                    '/dashboard/poultry/flock-management/' . $f->id
                ));
            }
        }

        return $items->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function mortalitySpikeAlerts(
        Farm $farm,
        ?Flock $flock,
        int $windowDays,
        float $threshold
    ): Collection {
        $today = Carbon::today();
        $query = PoultryMortalityReport::where('farm_id', $farm->id)
            ->whereDate('date', '>=', $today->copy()->subDays(max(1, $windowDays))->toDateString())
            ->where('mortality_percentage', '>=', $threshold);

        if ($flock) {
            $query->where('flock_id', $flock->id);
        }

        $flockNames = Flock::where('farm_id', $farm->id)->pluck('name', 'id');

        return $query->orderByDesc('date')->get()->map(function (PoultryMortalityReport $report) use ($flockNames) {
            $flockName = $flockNames[$report->flock_id] ?? 'Flock';
            $pct = (float) $report->mortality_percentage;

            return $this->alert(
                'mort-' . $report->id,
                $pct >= 5 ? 'critical' : 'warning',
                'mortality_spike',
                'Mortality spike: ' . $flockName,
                sprintf('%d birds (%.2f%%) on %s', (int) $report->mortality_count, $pct, Carbon::parse($report->date)->toDateString()),
                Carbon::parse($report->date)->toDateString(),
                $report->flock_id,
                $flockName,
                $report->flock_id ? '/dashboard/poultry/flock-management/' . $report->flock_id : null
            );
        })->values();
    }

    /**
     * Collect upcoming medication/vaccination items for a flock.
     *
     * Age is placement-based: current age = arrival_age_days + days since arrival.
     * Template schedule items due within the reminder window (including overdue
     * unfinished items) are included once each.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function collectUpcomingItems(Farm $farm, Flock $flock, int $windowDays): Collection
    {
        $timezone = method_exists($farm, 'resolveTimezone') ? $farm->resolveTimezone() : config('app.timezone', 'UTC');
        $today = Carbon::today($timezone);

        $arrivalDate = Carbon::parse($flock->arrival_date, $timezone)->startOfDay();
        $arrivalAge = (int) ($flock->arrival_age_days ?? 0);
        $daysSinceArrival = $arrivalDate->lte($today)
            ? $arrivalDate->diffInDays($today)
            : 0;
        $currentAgeDays = $arrivalAge + $daysSinceArrival;
        $maxAgeDays = $currentAgeDays + $windowDays;
        // Include a short overdue lookback so unfinished past due items still surface.
        $minAgeDays = max(0, $currentAgeDays - min(7, max(1, $windowDays)));
        $fromDate = $today->copy()->subDays(min(7, max(1, $windowDays)));
        $toDate = $today->copy()->addDays($windowDays);

        $batchSchedules = BatchSchedule::with([
            'schedule.items.poultryVaccine',
            'schedule.items.administrationMethods.administrationMethod',
            'items',
        ])
            ->where('flock_id', $flock->id)
            ->whereHas('schedule', function ($q) {
                $q->whereIn('schedule_type', ['medication', 'vaccination']);
            })
            ->get();

        $completedKeys = [];
        $recurringItemIds = [];
        foreach ($batchSchedules as $batch) {
            foreach ($batch->schedule?->items ?? [] as $templateItem) {
                if ($templateItem->is_recurring) {
                    $recurringItemIds[(int) $templateItem->id] = true;
                }
            }
        }

        $materializedBatchIds = $batchSchedules
            ->filter(fn (BatchSchedule $batch) => ($batch->items ?? collect())->contains(
                fn ($item) => strtolower((string) ($item->status ?? '')) === 'scheduled'
            ))
            ->pluck('id')
            ->all();

        foreach ($batchSchedules as $batch) {
            foreach ($batch->items ?? [] as $executed) {
                $status = strtolower((string) ($executed->status ?? ''));
                if (! in_array($status, ['completed', 'done', 'administered', 'skipped', 'cancelled'], true)) {
                    continue;
                }
                $scheduleItemId = (int) ($executed->schedule_item_id ?? 0);
                if ($scheduleItemId <= 0) {
                    continue;
                }
                $dateKey = $executed->scheduled_date
                    ? Carbon::parse($executed->scheduled_date)->toDateString()
                    : 'any';
                $completedKeys[$scheduleItemId . '_' . $dateKey] = true;
                if (! isset($recurringItemIds[$scheduleItemId])) {
                    $completedKeys[$scheduleItemId . '_any'] = true;
                }
            }
        }

        $upcoming = collect();
        $seenItemIds = [];
        $generator = app(MedVacBatchScheduleItemGenerator::class);
        $endDate = $flock->expected_end_date
            ? Carbon::parse($flock->expected_end_date, $timezone)->startOfDay()
            : null;

        foreach ($batchSchedules as $batch) {
            if (! $batch->schedule || in_array($batch->id, $materializedBatchIds, true)) {
                continue;
            }

            $scheduleType = $batch->schedule->schedule_type ?? 'medication';
            $type = $scheduleType === 'vaccination' ? 'vaccination' : 'medication';

            foreach ($batch->schedule->items as $item) {
                $dates = $generator->expandOccurrenceDates($item, $flock, $endDate);

                foreach ($dates as $scheduledDate) {
                    if ($scheduledDate->lt($fromDate) || $scheduledDate->gt($toDate)) {
                        continue;
                    }

                    $dateKey = $scheduledDate->toDateString();
                    if (
                        isset($completedKeys[$item->id . '_' . $dateKey])
                        || isset($completedKeys[$item->id . '_any'])
                    ) {
                        continue;
                    }

                    $daysUntilDate = (int) round(
                        $today->copy()->startOfDay()->diffInDays($scheduledDate->copy()->startOfDay(), false)
                    );

                    $itemKey = $type . '_' . $item->id . '_' . $dateKey;
                    if (isset($seenItemIds[$itemKey])) {
                        continue;
                    }
                    $seenItemIds[$itemKey] = true;

                    $upcoming->push($this->buildUpcomingItemPayload(
                        $type,
                        $item,
                        $batch,
                        $flock,
                        $dateKey,
                        $daysUntilDate,
                        null,
                        null
                    ));
                }
            }
        }

        $existingItems = BatchScheduleItem::with([
            'scheduleItem.poultryVaccine',
            'scheduleItem.administrationMethods.administrationMethod',
            'batchSchedule.schedule',
        ])
            ->whereHas('batchSchedule', function ($q) use ($flock) {
                $q->where('flock_id', $flock->id);
            })
            ->whereBetween('scheduled_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereIn('status', ['scheduled', 'late', 'overdue', 'pending']);
            })
            ->whereHas('scheduleItem', function ($q) {
                $q->whereNotNull('poultry_medication_id')
                    ->orWhereNotNull('poultry_vaccine_id');
            })
            ->get();

        foreach ($existingItems as $item) {
            $scheduleItem = $item->scheduleItem;
            if (! $scheduleItem) {
                continue;
            }

            $type = $scheduleItem->poultry_vaccine_id ? 'vaccination' : 'medication';
            $scheduledDate = Carbon::parse($item->scheduled_date)->startOfDay();
            $daysUntilDate = (int) round(
                $today->copy()->startOfDay()->diffInDays($scheduledDate->copy()->startOfDay(), false)
            );
            $itemKey = $type . '_' . $scheduleItem->id . '_' . $scheduledDate->toDateString();
            if (isset($seenItemIds[$itemKey])) {
                continue;
            }
            $seenItemIds[$itemKey] = true;

            $batch = $item->batchSchedule;
            if (! $batch) {
                continue;
            }

            $upcoming->push($this->buildUpcomingItemPayload(
                $type,
                $scheduleItem,
                $batch,
                $flock,
                $scheduledDate->toDateString(),
                $daysUntilDate,
                $item->cost !== null ? (float) $item->cost : null,
                (int) $item->id,
                $item->status ?? ($daysUntilDate < 0 ? 'overdue' : 'scheduled')
            ));
        }

        return $upcoming;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUpcomingItemPayload(
        string $type,
        ScheduleItem $scheduleItem,
        BatchSchedule $batch,
        Flock $flock,
        string $dateKey,
        int $daysUntilDate,
        ?float $cost = null,
        ?int $batchScheduleItemId = null,
        ?string $statusOverride = null
    ): array {
        $adminMethod = null;
        if ($scheduleItem->relationLoaded('administrationMethods')) {
            $links = $scheduleItem->administrationMethods;
            $preferred = $links->firstWhere('is_preferred', true) ?? $links->first();
            $adminMethod = optional(optional($preferred)->administrationMethod)->name;
        }

        $vaccineName = optional($scheduleItem->poultryVaccine)->name;
        $status = $statusOverride ?? ($daysUntilDate < 0 ? 'overdue' : 'scheduled');

        return [
            'id' => $batchScheduleItemId ?? $scheduleItem->id,
            'schedule_item_id' => $scheduleItem->id,
            'batch_schedule_id' => $batch->id,
            'batch_schedule_item_id' => $batchScheduleItemId,
            'type' => $type,
            'title' => $scheduleItem->name ?? ucfirst($type) . ' schedule',
            'vaccine_name' => $type === 'vaccination' ? ($vaccineName ?: $scheduleItem->name) : null,
            'scheduled_date' => $dateKey,
            'days_until' => $daysUntilDate,
            'status' => $status,
            'flock_name' => $flock->name,
            'cost' => $cost,
            'age_days' => (int) ($scheduleItem->age_days ?? 0),
            'description' => $scheduleItem->description,
            'dose' => $scheduleItem->dose,
            'dose_unit' => $scheduleItem->dose_unit,
            'administration_method' => $adminMethod,
            'schedule_name' => optional($batch->schedule)->name,
            'poultry_vaccine_id' => $scheduleItem->poultry_vaccine_id,
        ];
    }

    /**
     * Actionable low stock: remaining quantity below threshold, and not already
     * closed/depleted out of usable inventory.
     *
     * @param  list<string>  $excludedStatuses
     */
    private function isActionableLowStock(
        float $remainingQuantity,
        float $threshold,
        ?string $status,
        array $excludedStatuses
    ): bool {
        $normalized = strtolower((string) ($status ?? ''));
        if ($normalized !== '' && in_array($normalized, $excludedStatuses, true)) {
            return false;
        }

        return $remainingQuantity <= $threshold;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function missedFeedingAlerts(Farm $farm, ?Flock $flock): Collection
    {
        $flocks = $flock
            ? collect([$flock])
            : Flock::where('farm_id', $farm->id)->where('status', 'active')->get();

        $items = collect();

        foreach ($flocks as $activeFlock) {
            $batches = FeedingBatchSchedule::where('flock_id', $activeFlock->id)->get();
            foreach ($batches as $batch) {
                $result = $this->feedingMissedScheduleService->listMissedDays($batch, $activeFlock);
                $count = (int) ($result['count'] ?? 0);
                if ($count <= 0) {
                    continue;
                }

                $totalKg = (float) ($result['total_feed_kg'] ?? 0);
                $items->push($this->alert(
                    'missed-feed-' . $activeFlock->id . '-' . $batch->id,
                    $count >= 3 ? 'critical' : 'warning',
                    'missed_feeding',
                    'Missed feedings: ' . $activeFlock->name,
                    sprintf(
                        '%d day(s) behind schedule (%.2f kg planned)',
                        $count,
                        $totalKg
                    ),
                    Carbon::today()->toDateString(),
                    $activeFlock->id,
                    $activeFlock->name,
                    '/dashboard/poultry/flock-management/' . $activeFlock->id
                ));
            }
        }

        return $items->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function overdueTaskAlerts(Farm $farm): Collection
    {
        return FarmTaskInstance::query()
            ->where('farm_id', $farm->id)
            ->where('status', 'overdue')
            ->orderBy('scheduled_date')
            ->limit(25)
            ->get()
            ->map(function (FarmTaskInstance $task) {
                $date = $task->scheduled_date
                    ? Carbon::parse($task->scheduled_date)->toDateString()
                    : null;

                return $this->alert(
                    'task-overdue-' . $task->id,
                    'warning',
                    'overdue_task',
                    'Overdue task: ' . $task->title,
                    'Was due ' . ($date ?? 'recently') . ($task->section ? ' · ' . $task->section : ''),
                    $date,
                    null,
                    null,
                    '/dashboard/poultry/tasks'
                );
            })
            ->values();
    }

    private function resolveMinStockThreshold(mixed $value, float $default = 10.0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $threshold = (float) $value;

        return $threshold > 0 ? $threshold : $default;
    }

    /**
     * Expiry alerts only apply when there is remaining stock to act on.
     *
     * @param  list<string>  $excludedStatuses
     */
    private function isActionableExpiryInventory(object $inv, array $excludedStatuses): bool
    {
        $status = strtolower((string) ($inv->status ?? ''));
        if ($status !== '' && in_array($status, $excludedStatuses, true)) {
            return false;
        }

        return (float) ($inv->quantity ?? 0) > 0;
    }

    /**
     * @param  list<string>  $badStatuses
     * @deprecated Prefer isActionableLowStock()
     */
    private function isLowStock(float $quantity, float $threshold, ?string $status, array $badStatuses): bool
    {
        return $this->isActionableLowStock($quantity, $threshold, $status, $badStatuses);
    }

    /**
     * Returns critical (expired), warning (within 30 days), or null.
     */
    private function expirySeverity(mixed $expiryDate): ?string
    {
        if (! $expiryDate) {
            return null;
        }

        try {
            $exp = Carbon::parse($expiryDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $today = Carbon::today();
        if ($exp->lt($today)) {
            return 'critical';
        }
        if ($exp->lte($today->copy()->addDays(30))) {
            return 'warning';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function alert(
        string $id,
        string $severity,
        string $category,
        string $title,
        string $detail,
        ?string $date,
        ?int $flockId,
        ?string $flockName,
        ?string $link
    ): array {
        return [
            'id' => $id,
            'severity' => $severity,
            'category' => $category,
            'title' => $title,
            'detail' => $detail,
            'date' => $date,
            'flock_id' => $flockId,
            'flock_name' => $flockName,
            'link' => $link,
        ];
    }
}
