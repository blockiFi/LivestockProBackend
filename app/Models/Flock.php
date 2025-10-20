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

class Flock extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'batch_number',
        'breed',
        'source',
        'quantity',
        'arrival_date',
        'arrival_age_days',
        'expected_end_date',
        'notes',
        'status',
        'farm_id',
        'house_id',
        'poultry_type_id',
        'flock_stage_id'
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function poultryHouse(): BelongsTo
    {
        return $this->belongsTo(PoultryHouse::class, 'house_id');
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
} 