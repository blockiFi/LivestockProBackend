<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleItem extends Model
{
    protected $fillable = [
        'name',
        'description',
        'day_number',
        'schedule_id',
        'medication_product_id',
        'poultry_vaccine_product_id',
        'created_by'
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
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