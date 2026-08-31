<?php

namespace App\Services;

use App\Models\BatchSchedule;
use App\Models\BatchScheduleItem;
use App\Models\FlockExpenditure;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryMedicationRecord;
use App\Models\PoultryVaccinationRecord;
use Illuminate\Support\Collection;

class ExpenditureClassificationService
{
    /**
     * Keyword rules ordered from most specific to least specific.
     *
     * @var array<string, list<string>>
     */
    private const DESCRIPTION_RULES = [
        FlockExpenditure::CATEGORY_CHICKS => [
            'day-old chick',
            'day old chick',
            'day-old',
            'doc purchase',
            'chick purchase',
            'placement cost',
            'pullet purchase',
            'point of lay',
            'bird purchase',
            'purchased birds',
            'purchase of birds',
            'buying birds',
            'stock birds',
            'bird stocking',
            'birds placement',
            'bird placement',
            'layers purchase',
            'broiler purchase',
            'birds',
            'bird stock',
        ],
        FlockExpenditure::CATEGORY_VACCINATION => [
            'vaccination',
            'vaccine',
            'immunization',
            'immunisation',
            'lasota',
            'gumboro',
            'newcastle',
        ],
        FlockExpenditure::CATEGORY_MEDICATION => [
            'medication',
            'medicine',
            'antibiotic',
            'coccidiostat',
            'dewormer',
            'de-wormer',
            'vitamin supplement',
            'multivitamin',
            'tylosin',
            'oxytetracycline',
        ],
        FlockExpenditure::CATEGORY_FEED => [
            'feed purchase',
            'feed usage',
            'starter feed',
            'grower feed',
            'finisher feed',
            'layer mash',
            'feed concentrate',
            'damaged feed',
            'feed write-off',
            'feed write off',
        ],
        FlockExpenditure::CATEGORY_LABOUR => [
            'labour',
            'labor',
            'wage',
            'wages',
            'salary',
            'payroll',
            'farmhand',
            'farm hand',
            'worker',
            'casual staff',
            'staff payment',
            'attendant',
        ],
        FlockExpenditure::CATEGORY_TRANSPORT => [
            'transport',
            'transportation',
            'delivery fee',
            'haulage',
            'logistics',
            'freight',
            'fuel',
            'diesel',
            'petrol',
            'vehicle',
            'truck',
        ],
        FlockExpenditure::CATEGORY_UTILITIES => [
            'utility',
            'utilities',
            'electricity',
            'water bill',
            'power bill',
            'generator',
            'nepa',
            'prepaid meter',
            'diesel for generator',
        ],
        FlockExpenditure::CATEGORY_HOUSING => [
            'housing',
            'pen construction',
            'poultry house',
            'house rent',
            'pen rent',
            'shed construction',
            'building materials',
        ],
        FlockExpenditure::CATEGORY_EQUIPMENT => [
            'equipment',
            'feeder',
            'drinker',
            'brooder',
            'incubator',
            'scale',
            'weighing scale',
            'tool purchase',
            'cage',
            'battery cage',
        ],
        FlockExpenditure::CATEGORY_MAINTENANCE => [
            'maintenance',
            'repair',
            'servicing',
            'renovation',
            'pen repair',
            'fix pen',
            'roof repair',
        ],
    ];

    /**
     * @return array{
     *   scanned: int,
     *   category_updated: int,
     *   description_updated: int,
     *   unchanged: int,
     *   changes: list<array<string, mixed>>
     * }
     */
    public function reclassifyAll(bool $dryRun = false, ?int $farmId = null): array
    {
        $query = FlockExpenditure::query()->orderBy('id');

        if ($farmId !== null) {
            $query->where('farm_id', $farmId);
        }

        $stats = [
            'scanned' => 0,
            'category_updated' => 0,
            'description_updated' => 0,
            'unchanged' => 0,
            'changes' => [],
        ];

        $query->chunkById(200, function (Collection $records) use (&$stats, $dryRun) {
            foreach ($records as $expenditure) {
                $stats['scanned']++;
                $result = $this->reclassify($expenditure, $dryRun);

                if ($result['category_changed']) {
                    $stats['category_updated']++;
                }

                if ($result['description_changed']) {
                    $stats['description_updated']++;
                }

                if (! $result['category_changed'] && ! $result['description_changed']) {
                    $stats['unchanged']++;
                }

                if ($result['category_changed'] || $result['description_changed']) {
                    $stats['changes'][] = $result;
                }
            }
        });

        return $stats;
    }

    /**
     * @return array{
     *   id: int,
     *   category_changed: bool,
     *   description_changed: bool,
     *   from_category: string,
     *   to_category: string,
     *   from_description: string|null,
     *   to_description: string|null,
     *   source_type: string|null
     * }
     */
    public function reclassify(FlockExpenditure $expenditure, bool $dryRun = false): array
    {
        $originalCategory = (string) $expenditure->category;
        $originalDescription = $expenditure->description;
        $targetCategory = $this->resolveCategory($expenditure);
        $targetDescription = $this->resolveDescription($expenditure, $targetCategory);

        $categoryChanged = $targetCategory !== null && $targetCategory !== $originalCategory;
        $descriptionChanged = $targetDescription !== null
            && $this->normalizeText($targetDescription) !== $this->normalizeText((string) ($originalDescription ?? ''));

        if (! $dryRun && ($categoryChanged || $descriptionChanged)) {
            $updates = [];

            if ($categoryChanged && $targetCategory !== null) {
                $updates['category'] = $targetCategory;
            }

            if ($descriptionChanged && $targetDescription !== null) {
                $updates['description'] = $targetDescription;
            }

            if ($updates !== []) {
                $expenditure->update($updates);
            }
        }

        return [
            'id' => (int) $expenditure->id,
            'category_changed' => $categoryChanged,
            'description_changed' => $descriptionChanged,
            'from_category' => $originalCategory,
            'to_category' => $categoryChanged ? (string) $targetCategory : $originalCategory,
            'from_description' => $originalDescription,
            'to_description' => $descriptionChanged ? $targetDescription : $originalDescription,
            'source_type' => $expenditure->source_type,
        ];
    }

    public function classifyFromDescription(?string $description): ?string
    {
        $haystack = $this->normalizeText($description ?? '');
        if ($haystack === '') {
            return null;
        }

        foreach (self::DESCRIPTION_RULES as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $this->normalizeText($keyword))) {
                    return $category;
                }
            }
        }

        return null;
    }

    private function resolveCategory(FlockExpenditure $expenditure): ?string
    {
        if (! $expenditure->isManual()) {
            return $this->resolveAutoCategory($expenditure);
        }

        $current = (string) $expenditure->category;

        if ($current !== FlockExpenditure::CATEGORY_OTHER) {
            return $current;
        }

        return $this->classifyFromDescription($expenditure->description) ?? $current;
    }

    private function resolveAutoCategory(FlockExpenditure $expenditure): ?string
    {
        return match ($expenditure->source_type) {
            'feed_usage', 'feed_inventory_close' => FlockExpenditure::CATEGORY_FEED,
            'medication_record' => FlockExpenditure::CATEGORY_MEDICATION,
            'vaccination_record' => FlockExpenditure::CATEGORY_VACCINATION,
            'batch_schedule_item' => $this->resolveBatchScheduleItemCategory((int) ($expenditure->source_id ?? 0)),
            default => (string) $expenditure->category,
        };
    }

    private function resolveBatchScheduleItemCategory(int $batchScheduleItemId): string
    {
        if ($batchScheduleItemId <= 0) {
            return FlockExpenditure::CATEGORY_MEDICATION;
        }

        $item = BatchScheduleItem::with(['scheduleItem', 'batchSchedule.schedule'])->find($batchScheduleItemId);
        if (! $item) {
            return FlockExpenditure::CATEGORY_MEDICATION;
        }

        $scheduleType = strtolower((string) optional(optional($item->batchSchedule)->schedule)->schedule_type);
        if ($scheduleType === 'vaccination') {
            return FlockExpenditure::CATEGORY_VACCINATION;
        }

        if ($scheduleType === 'medication') {
            return FlockExpenditure::CATEGORY_MEDICATION;
        }

        if ($item->scheduleItem?->poultry_vaccine_id) {
            return FlockExpenditure::CATEGORY_VACCINATION;
        }

        return FlockExpenditure::CATEGORY_MEDICATION;
    }

    private function resolveDescription(FlockExpenditure $expenditure, ?string $category): ?string
    {
        if ($expenditure->isManual()) {
            return $this->shouldEnrichManualDescription($expenditure)
                ? $this->buildManualDescription($expenditure, $category)
                : null;
        }

        return $this->buildAutoDescription($expenditure, $category);
    }

    private function shouldEnrichManualDescription(FlockExpenditure $expenditure): bool
    {
        $description = trim((string) ($expenditure->description ?? ''));

        return $description === ''
            || in_array(strtolower($description), [
                'other',
                'misc',
                'miscellaneous',
                'expense',
                'expenditure',
                'manual entry',
            ], true);
    }

    private function buildManualDescription(FlockExpenditure $expenditure, ?string $category): ?string
    {
        $label = $this->categoryLabel($category ?? (string) $expenditure->category);

        return $label !== '' ? "{$label} expense" : null;
    }

    private function buildAutoDescription(FlockExpenditure $expenditure, ?string $category): ?string
    {
        return match ($expenditure->source_type) {
            'feed_usage' => $this->describeFeedUsage((int) ($expenditure->source_id ?? 0)),
            'feed_inventory_close' => $expenditure->description,
            'medication_record' => $this->describeMedicationRecord((int) ($expenditure->source_id ?? 0)),
            'vaccination_record' => $this->describeVaccinationRecord((int) ($expenditure->source_id ?? 0)),
            'batch_schedule_item' => $this->describeBatchScheduleItem((int) ($expenditure->source_id ?? 0), $category),
            default => null,
        };
    }

    private function describeFeedUsage(int $usageId): ?string
    {
        if ($usageId <= 0) {
            return 'Feed usage';
        }

        $usage = PoultryFeedUsage::with(['feedType', 'feedInventory'])->find($usageId);
        if (! $usage) {
            return 'Feed usage';
        }

        $feedName = optional($usage->feedType)->name
            ?? optional($usage->feedInventory)->batch_number
            ?? 'Feed';
        $quantity = number_format((float) ($usage->quantity ?? 0), 2);

        return "Feed usage — {$feedName} ({$quantity} kg)";
    }

    private function describeMedicationRecord(int $recordId): ?string
    {
        if ($recordId <= 0) {
            return 'Medication record';
        }

        $record = PoultryMedicationRecord::with('medication')->find($recordId);
        $name = optional($record?->medication)->name ?? 'Medication';

        return "Medication — {$name}";
    }

    private function describeVaccinationRecord(int $recordId): ?string
    {
        if ($recordId <= 0) {
            return 'Vaccination record';
        }

        $record = PoultryVaccinationRecord::with('vaccine')->find($recordId);
        $name = optional($record?->vaccine)->name ?? 'Vaccination';

        return "Vaccination — {$name}";
    }

    private function describeBatchScheduleItem(int $itemId, ?string $category): ?string
    {
        if ($itemId <= 0) {
            return null;
        }

        $item = BatchScheduleItem::with(['scheduleItem', 'batchSchedule.schedule'])->find($itemId);
        if (! $item) {
            return null;
        }

        $itemName = $item->scheduleItem?->name ?? 'Scheduled item';
        $prefix = $category === FlockExpenditure::CATEGORY_VACCINATION ? 'Vaccination' : 'Medication';
        $scheduleName = optional(optional($item->batchSchedule)->schedule)->name;

        return $scheduleName
            ? "{$prefix} — {$itemName} ({$scheduleName})"
            : "{$prefix} — {$itemName}";
    }

    private function categoryLabel(?string $category): string
    {
        return match ($category) {
            FlockExpenditure::CATEGORY_FEED => 'Feed',
            FlockExpenditure::CATEGORY_MEDICATION => 'Medication',
            FlockExpenditure::CATEGORY_VACCINATION => 'Vaccination',
            FlockExpenditure::CATEGORY_LABOUR => 'Labour',
            FlockExpenditure::CATEGORY_TRANSPORT => 'Transport',
            FlockExpenditure::CATEGORY_UTILITIES => 'Utilities',
            FlockExpenditure::CATEGORY_EQUIPMENT => 'Equipment',
            FlockExpenditure::CATEGORY_HOUSING => 'Housing',
            FlockExpenditure::CATEGORY_CHICKS => 'Day-old chicks',
            FlockExpenditure::CATEGORY_MAINTENANCE => 'Maintenance',
            FlockExpenditure::CATEGORY_OTHER => 'Other',
            default => ucfirst(str_replace('_', ' ', (string) $category)),
        };
    }

    private function normalizeText(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }
}
