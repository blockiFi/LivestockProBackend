<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PoultryMedicationInventory extends Model
{
    protected $fillable = [
        'quantity',
        'available_quantity',
        'unit_cost',
        'expiry_date',
        'batch_number',
        'farm_id',
        'medication_product_id',
        'created_by',
        'status',
        'manufacturer',
        'notes',
        'manufacture_date',
        'last_restocked'
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

    /**
     * Update the status based on current quantity
     */
    public function updateStatus()
    {
        if ($this->quantity <= 0) {
            $this->status = 'depleted';
        } elseif ($this->quantity <= 10) { // Low stock threshold - can be made configurable
            $this->status = 'in_use';
        } else {
            $this->status = 'available';
        }
        
        $this->save();
    }

    /**
     * Check if inventory has sufficient quantity
     */
    public function hasSufficientQuantity($requiredQuantity)
    {
        return $this->quantity >= $requiredQuantity;
    }
}