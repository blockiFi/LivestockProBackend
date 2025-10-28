<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PoultryVaccineInventory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'poultry_vaccine_product_id',
        'farm_id',
        'quantity',
        'available_quantity',
        'status',
        'batch_number',
        'manufacture_date',
        'expiry_date',
        'unit_cost',
        'created_by'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'available_quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'manufacture_date' => 'date',
        'expiry_date' => 'date',
    ];

    // Remove the problematic accessors that convert to strings
    // We need numeric values for arithmetic operations
 
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PoultryVaccineProduct::class, 'poultry_vaccine_product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vaccinationRecords(): HasMany
    {
        return $this->hasMany(PoultryVaccinationRecord::class);
    }

    /**
     * Scope a query to only include available inventory.
     */
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    /**
     * Scope a query to only include in-use inventory.
     */
    public function scopeInUse($query)
    {
        return $query->where('status', 'in_use');
    }

    /**
     * Scope a query to only include depleted inventory.
     */
    public function scopeDepleted($query)
    {
        return $query->where('status', 'depleted');
    }

    /**
     * Scope a query to only include inventory that hasn't expired.
     */
    public function scopeNotExpired($query)
    {
        return $query->where('expiry_date', '>', now());
    }

    /**
     * Scope a query to only include inventory that is expiring soon (within 30 days).
     */
    public function scopeExpiringSoon($query)
    {
        return $query->where('expiry_date', '<=', now()->addDays(30))
                    ->where('expiry_date', '>', now());
    }

    /**
     * Get the total value of this inventory item.
     */
    public function getTotalValueAttribute()
    {
        return $this->quantity * $this->unit_cost;
    }

    /**
     * Check if this inventory is expired.
     */
    public function isExpired()
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    /**
     * Check if this inventory is expiring soon (within 30 days).
     */
    public function isExpiringSoon()
    {
        return $this->expiry_date && 
               $this->expiry_date->isFuture() && 
               $this->expiry_date->diffInDays(now()) <= 30;
    }
}