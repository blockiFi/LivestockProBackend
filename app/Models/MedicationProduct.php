<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\MedicationDosage;

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
        'min_stock_level',
        'preventive_medicine_amount',
        'preventive_medicine_unit',
        'preventive_diluent_amount',
        'preventive_diluent_unit',
        'preventive_diluent_type',
        'preventive_diluent_label',
        'treatment_medicine_amount',
        'treatment_medicine_unit',
        'treatment_diluent_amount',
        'treatment_diluent_unit',
        'treatment_diluent_type',
        'treatment_diluent_label',
    ];

    protected $casts = [
        'withdrawal_period' => 'integer',
        'dosage' => 'decimal:4',
        'min_stock_level' => 'integer',
        'preventive_medicine_amount' => 'decimal:4',
        'preventive_diluent_amount' => 'decimal:4',
        'treatment_medicine_amount' => 'decimal:4',
        'treatment_diluent_amount' => 'decimal:4',
    ];

    protected $appends = [
        'preventive_dosage_label',
        'treatment_dosage_label',
    ];

    public function getPreventiveDosageLabelAttribute(): ?string
    {
        return MedicationDosage::formatRatio($this, 'preventive');
    }

    public function getTreatmentDosageLabelAttribute(): ?string
    {
        return MedicationDosage::formatRatio($this, 'treatment');
    }

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

    public function administrationMethod(): BelongsTo
    {
        return $this->belongsTo(AdministrationMethod::class);
    }
}
