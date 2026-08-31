<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleItem extends Model
{
    protected $fillable = [
        'schedule_id',
        'age_days',
        'poultry_vaccine_id',
        'poultry_medication_id',
        'name',
        'dose_unit',
        'dose',
        'withdrawal_period_days',
        'storage_instructions',
        'description',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function poultryVaccine(): BelongsTo
    {
        return $this->belongsTo(PoultryVaccine::class, 'poultry_vaccine_id');
    }

    public function administrationMethods(): HasMany
    {
        return $this->hasMany(ScheduleItemAdministrationMethod::class);
    }

    public function batchScheduleItems(): HasMany
    {
        return $this->hasMany(BatchScheduleItem::class);
    }

    public function medicationProduct(): BelongsTo
    {
        return $this->belongsTo(MedicationProduct::class);
    }

    public function vaccineProduct(): BelongsTo
    {
        return $this->belongsTo(PoultryVaccineProduct::class, 'poultry_vaccine_product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
} 