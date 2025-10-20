<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PoultryMedicationInventory extends Model
{
    protected $fillable = [
        'quantity',
        'unit_cost',
        'expiry_date',
        'batch_number',
        'farm_id',
        'medication_product_id',
        'created_by'
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MedicationProduct::class, 'medication_product_id');
    }

    public function medicationRecords(): HasMany
    {
        return $this->hasMany(PoultryMedicationRecord::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
} 