<?php

namespace App\Models;

use App\Services\ExpenditureClassificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlockExpenditure extends Model
{
    use HasFactory, SoftDeletes;

    public const CATEGORY_FEED = 'feed';

    public const CATEGORY_MEDICATION = 'medication';

    public const CATEGORY_VACCINATION = 'vaccination';

    public const CATEGORY_LABOUR = 'labour';

    public const CATEGORY_TRANSPORT = 'transport';

    public const CATEGORY_UTILITIES = 'utilities';

    public const CATEGORY_EQUIPMENT = 'equipment';

    public const CATEGORY_HOUSING = 'housing';

    public const CATEGORY_CHICKS = 'chicks';

    public const CATEGORY_MAINTENANCE = 'maintenance';

    public const CATEGORY_OTHER = 'other';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_FEED,
        self::CATEGORY_MEDICATION,
        self::CATEGORY_VACCINATION,
        self::CATEGORY_LABOUR,
        self::CATEGORY_TRANSPORT,
        self::CATEGORY_UTILITIES,
        self::CATEGORY_EQUIPMENT,
        self::CATEGORY_HOUSING,
        self::CATEGORY_CHICKS,
        self::CATEGORY_MAINTENANCE,
        self::CATEGORY_OTHER,
    ];

    /** @var list<string> */
    public const MANUAL_CATEGORIES = [
        self::CATEGORY_FEED,
        self::CATEGORY_MEDICATION,
        self::CATEGORY_VACCINATION,
        self::CATEGORY_LABOUR,
        self::CATEGORY_TRANSPORT,
        self::CATEGORY_UTILITIES,
        self::CATEGORY_EQUIPMENT,
        self::CATEGORY_HOUSING,
        self::CATEGORY_CHICKS,
        self::CATEGORY_MAINTENANCE,
        self::CATEGORY_OTHER,
    ];

    protected $fillable = [
        'farm_id',
        'flock_id',
        'category',
        'amount',
        'currency',
        'description',
        'payment_method',
        'reference_no',
        'date',
        'source_type',
        'source_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function flock()
    {
        return $this->belongsTo(Flock::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isManual(): bool
    {
        return $this->source_type === null || $this->source_type === 'manual';
    }

    /**
     * Propagate an expenditure date change back to the linked source record.
     */
    public function syncSourceDate(string $date): void
    {
        if ($this->isManual() || !$this->source_id) {
            return;
        }

        match ($this->source_type) {
            'feed_usage' => PoultryFeedUsage::where('id', $this->source_id)->update([
                'usage_date' => $date,
            ]),
            'medication_record' => PoultryMedicationRecord::where('id', $this->source_id)->update([
                'date' => $date,
            ]),
            'vaccination_record' => PoultryVaccinationRecord::where('id', $this->source_id)->update([
                'date' => $date,
            ]),
            'batch_schedule_item' => $this->syncBatchScheduleItemDate($date),
            default => null,
        };
    }

    protected function syncBatchScheduleItemDate(string $date): void
    {
        $item = BatchScheduleItem::find($this->source_id);
        if (!$item) {
            return;
        }

        if ($item->actual_date) {
            $item->update(['actual_date' => $date]);
        } else {
            $item->update(['scheduled_date' => $date]);
        }
    }

    /**
     * Create or update an expenditure record from a feed usage entry.
     */
    public static function recordFromFeedUsage(PoultryFeedUsage $usage): ?self
    {
        $unitCost = $usage->unit_cost !== null ? (float) $usage->unit_cost : 0.0;

        if ($unitCost <= 0) {
            $inventory = $usage->relationLoaded('feedInventory')
                ? $usage->feedInventory
                : $usage->feedInventory()->first();

            $unitCost = $inventory && $inventory->unit_cost !== null
                ? (float) $inventory->unit_cost
                : 0.0;

            if ($unitCost > 0 && (float) ($usage->unit_cost ?? 0) <= 0) {
                $usage->forceFill(['unit_cost' => $unitCost])->save();
            }
        }

        if ($unitCost <= 0) {
            return null;
        }

        $amount = round((float) $usage->quantity * $unitCost, 2);

        $existing = static::withTrashed()
            ->where('source_type', 'feed_usage')
            ->where('source_id', $usage->id)
            ->first();

        $payload = [
            'farm_id' => $usage->farm_id,
            'flock_id' => $usage->flock_id,
            'category' => 'feed',
            'amount' => $amount,
            'currency' => null,
            'description' => 'Feed usage',
            'date' => $usage->usage_date,
            'created_by' => $usage->created_by,
            'deleted_at' => null,
        ];

        if ($existing) {
            $existing->restore();
            $existing->update($payload);

            return static::finalizeAutoExpenditure($existing->fresh());
        }

        return static::finalizeAutoExpenditure(static::create(array_merge($payload, [
            'source_type' => 'feed_usage',
            'source_id' => $usage->id,
        ])));
    }

    /**
     * Create or update an expenditure record from a medication record entry.
     */
    public static function recordFromMedication(PoultryMedicationRecord $record): ?self
    {
        if ($record->cost === null || $record->cost <= 0) {
            return null;
        }

        return static::finalizeAutoExpenditure(static::updateOrCreate(
            [
                'source_type' => 'medication_record',
                'source_id' => $record->id,
            ],
            [
                'farm_id' => $record->farm_id,
                'flock_id' => $record->flock_id,
                'category' => 'medication',
                'amount' => $record->cost,
                'currency' => null,
                'description' => 'Medication record',
                'date' => $record->date,
                'created_by' => $record->recorded_by ?? null,
            ]
        ));
    }

    /**
     * Create or update an expenditure record from a vaccination record entry.
     */
    public static function recordFromVaccination(PoultryVaccinationRecord $record): ?self
    {
        if ($record->cost === null || $record->cost <= 0) {
            return null;
        }

        return static::finalizeAutoExpenditure(static::updateOrCreate(
            [
                'source_type' => 'vaccination_record',
                'source_id' => $record->id,
            ],
            [
                'farm_id' => $record->farm_id,
                'flock_id' => $record->flock_id,
                'category' => 'vaccination',
                'amount' => $record->cost,
                'currency' => null,
                'description' => 'Vaccination record',
                'date' => $record->date,
                'created_by' => $record->recorded_by ?? null,
            ]
        ));
    }

    /**
     * Create or update an expenditure from an implemented batch schedule item.
     */
    public static function recordFromBatchScheduleItem(
        BatchScheduleItem $item,
        string $scheduleType,
        ?int $createdBy = null
    ): ?self {
        if ($item->cost === null || (float) $item->cost <= 0) {
            return null;
        }

        $batchSchedule = $item->relationLoaded('batchSchedule')
            ? $item->batchSchedule
            : BatchSchedule::find($item->batch_schedule_id);

        if (! $batchSchedule) {
            return null;
        }

        $category = $scheduleType === 'medication' ? 'medication' : 'vaccination';
        $description = $scheduleType === 'medication'
            ? 'Medication schedule implementation'
            : 'Vaccination schedule implementation';

        return static::finalizeAutoExpenditure(static::updateOrCreate(
            [
                'source_type' => 'batch_schedule_item',
                'source_id' => $item->id,
            ],
            [
                'farm_id' => $batchSchedule->farm_id,
                'flock_id' => $batchSchedule->flock_id,
                'category' => $category,
                'amount' => $item->cost,
                'currency' => null,
                'description' => $description,
                'date' => $item->actual_date ?? $item->scheduled_date,
                'created_by' => $createdBy,
            ]
        ));
    }

    /**
     * Create or update an expenditure record from a damaged feed inventory close.
     */
    public static function recordFromDamagedInventoryClose(
        PoultryFeedInventory $inventory,
        int $flockId,
        float $damagedQuantity,
        ?int $closedBy = null
    ): ?self {
        $unitCost = $inventory->unit_cost !== null ? (float) $inventory->unit_cost : 0.0;

        if ($unitCost <= 0 || $damagedQuantity <= 0) {
            return null;
        }

        $amount = round($damagedQuantity * $unitCost, 2);
        if ($amount <= 0) {
            return null;
        }

        $batchLabel = $inventory->batch_number ?: "#{$inventory->id}";
        $feedName = $inventory->relationLoaded('feedType')
            ? ($inventory->feedType?->name ?? 'Feed')
            : ($inventory->feedType()->value('name') ?? 'Feed');

        return static::finalizeAutoExpenditure(static::updateOrCreate(
            [
                'source_type' => 'feed_inventory_close',
                'source_id' => $inventory->id,
            ],
            [
                'farm_id' => $inventory->farm_id,
                'flock_id' => $flockId,
                'category' => 'feed',
                'amount' => $amount,
                'currency' => null,
                'description' => "Damaged feed write-off — {$feedName} (batch {$batchLabel})",
                'date' => now()->toDateString(),
                'created_by' => $closedBy,
                'deleted_at' => null,
            ]
        ));
    }

    protected static function finalizeAutoExpenditure(?self $expenditure): ?self
    {
        if (! $expenditure) {
            return null;
        }

        app(ExpenditureClassificationService::class)->reclassify($expenditure);

        return $expenditure->fresh();
    }

    /**
     * Remove any expenditure record linked to a given source.
     */
    public static function deleteForSource(string $sourceType, int $sourceId): void
    {
        static::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->delete();
    }
}

