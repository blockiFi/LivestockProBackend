<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Farm;
use App\Models\PoultryHouse;
use App\Models\PoultryType;
use App\Models\FlockStage;
use App\Models\FlockDailyRecord;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryWeightReport;
use App\Models\PoultryEggReport;
use App\Models\PoultryBatchSchedule;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryMedicationRecord;
use App\Models\FlockHouseAllocation;
use App\Models\FlockTransfer;

class Flock extends Model
{
    use HasFactory;

    protected $appends = ['actual_quantity'];

    protected $fillable = [
        'name',
        'batch_number',
        'breed',
        'source',
        'quantity',
        'arrival_date',
        'arrival_age_days',
        'expected_end_date',
        'actual_end_date',
        'notes',
        'status',
        'farm_id',
        'house_id',
        'poultry_type_id',
        'flock_stage_id'
    ];

    protected $casts = [
        'arrival_date' => 'date',
        'expected_end_date' => 'date',
        'actual_end_date' => 'date',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the actual flock quantity after subtracting total mortality and culling.
     */
    public function getActualQuantityAttribute(): int
    {
        $totalMortality = (int) $this->mortalityReports()->sum('mortality_count');
        $totalCulling = (int) $this->dailyRecords()->sum('culling_count');

        return max(0, $this->quantity - $totalMortality - $totalCulling);
    }

    /**
     * Birds alive at the start of $date (before mortality/culls recorded on that day).
     */
    public function birdCountOnDate(string $date): int
    {
        $priorMortality = (int) $this->mortalityReports()
            ->whereDate('date', '<', $date)
            ->sum('mortality_count');
        $priorCulls = (int) $this->dailyRecords()
            ->whereDate('date', '<', $date)
            ->sum('culling_count');

        return max(0, (int) $this->quantity - $priorMortality - $priorCulls);
    }

    /**
     * Current birds per house after mortality/culling (allocation rows may still hold arrival qty).
     *
     * @return array<int, int> house_id => quantity
     */
    public function currentHouseAllocations(): array
    {
        $actual = $this->actual_quantity;
        /** @var array<int, int> $current */
        $current = $this->allocations()
            ->pluck('quantity', 'house_id')
            ->map(fn ($v) => (int) $v)
            ->toArray();

        if (count($current) === 0) {
            return $this->house_id ? [(int) $this->house_id => $actual] : [];
        }

        $sum = (int) array_sum($current);
        if ($sum === $actual) {
            return $current;
        }

        $diff = $sum - $actual;
        $primary = $this->house_id ? (int) $this->house_id : null;
        $houseIds = array_keys($current);
        usort($houseIds, function (int $a, int $b) use ($current, $primary): int {
            if ($primary !== null) {
                if ($a === $primary) {
                    return -1;
                }
                if ($b === $primary) {
                    return 1;
                }
            }

            return ($current[$b] ?? 0) <=> ($current[$a] ?? 0);
        });

        if ($diff > 0) {
            foreach ($houseIds as $houseId) {
                if ($diff <= 0) {
                    break;
                }
                $take = min($current[$houseId], $diff);
                $current[$houseId] -= $take;
                $diff -= $take;
            }
        } else {
            $need = abs($diff);
            $target = ($primary !== null && array_key_exists($primary, $current))
                ? $primary
                : (int) ($houseIds[0] ?? 0);
            if ($target) {
                $current[$target] = ($current[$target] ?? 0) + $need;
            }
        }

        return array_filter($current, fn (int $qty) => $qty > 0);
    }

    /**
     * Persist house allocation rows so they match birds still alive.
     */
    public function reconcileHouseAllocations(): void
    {
        $this->unsetRelation('allocations');

        $live = $this->currentHouseAllocations();
        $existing = $this->allocations()->get()->keyBy('house_id');

        foreach ($live as $houseId => $qty) {
            FlockHouseAllocation::updateOrCreate(
                ['flock_id' => $this->id, 'house_id' => (int) $houseId],
                ['farm_id' => (int) $this->farm_id, 'quantity' => (int) $qty]
            );
            $existing->forget($houseId);
        }

        foreach ($existing as $row) {
            $row->delete();
        }

        $this->unsetRelation('allocations');

        // Keep pen status in sync when allocations shrink to zero (sales, mortality, etc.).
        app(\App\Services\HouseStatusService::class)->recalculateForFlock($this);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function poultryHouse(): BelongsTo
    {
        return $this->belongsTo(PoultryHouse::class, 'house_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FlockHouseAllocation::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(FlockTransfer::class);
    }

    public function poultryType(): BelongsTo
    {
        return $this->belongsTo(PoultryType::class);
    }

    public function flockStage(): BelongsTo
    {
        return $this->belongsTo(FlockStage::class);
    }

    public function dailyRecords(): HasMany
    {
        return $this->hasMany(FlockDailyRecord::class);
    }

    public function mortalityReports(): HasMany
    {
        return $this->hasMany(PoultryMortalityReport::class);
    }

    public function weightReports(): HasMany
    {
        return $this->hasMany(PoultryFlockWeightReport::class);
    }

    public function eggReports(): HasMany
    {
        return $this->hasMany(PoultryFlockEggReport::class);
    }

    public function batchSchedules(): HasMany
    {
        return $this->hasMany(BatchSchedule::class);
    }
    public function BatchMedicationSchedules(): HasMany
    {
        return $this->hasMany(BatchSchedule::class)
            ->whereHas('schedule', function ($query) {
                $query->where('schedule_type', 'medication');
            });
    }
    public function batchFeedingSchedules(): HasMany
    {
        return $this->hasMany(FeedingBatchSchedule::class);
    }
    public function BatchVaccinationSchedules(): HasMany
    {
        return $this->hasMany(BatchSchedule::class)
            ->whereHas('schedule', function ($query) {
                $query->where('schedule_type', 'vaccination');
            });
    }

    public function poultryFeedUsages(): HasMany
    {
        return $this->hasMany(PoultryFeedUsage::class);
    }

    public function poultryMedicationRecords(): HasMany
    {
        return $this->hasMany(PoultryMedicationRecord::class);
    }

    public function poultryEvents(): HasMany
    {
        return $this->hasMany(PoultryEvent::class);
    }
    public function poultryFeedTypes(): HasMany
    {
        return $this->hasMany(PoultryFeedType::class)->where('type', 'default')->orWhere('farm_id', $this->farm_id);
    }

    public function poultryVaccinationRecords(): HasMany
    {
        return $this->hasMany(PoultryVaccinationRecord::class);
    }

    public function flockExpenditures(): HasMany
    {
        return $this->hasMany(FlockExpenditure::class);
    }

    public function flockSales(): HasMany
    {
        return $this->hasMany(FlockSale::class);
    }

    public function salesRecords(): HasMany
    {
        return $this->hasMany(SalesRecord::class);
    }
} 