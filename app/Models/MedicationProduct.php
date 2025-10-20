<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationProduct extends Model
{
    protected $fillable = [
        'farm_id',
        'type',
        'poultry_medication_id',
        'name',
        'image_url',
        'manufacturer',
        'administration_method_id',
        'withdrawal_period',
        'withdrawal_period_unit',
        'dosage',
        'dosage_unit',
    ];

    public function inventories(): HasMany
    {
        return $this->hasMany(PoultryMedicationInventory::class);
    }

    public function medicationRecords(): HasMany
    {
        return $this->hasMany(PoultryMedicationRecord::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function scheduleItems(): HasMany
    {
        return $this->hasMany(ScheduleItem::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(PoultryMedication::class, 'poultry_medication_id');
    }
} 