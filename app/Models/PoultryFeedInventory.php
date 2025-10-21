<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PoultryFeedInventory extends Model
{
    protected $fillable = [
        'quantity',
        'unit_cost',
        'expiry_date',
        'batch_number',
        'farm_id',
        'manufacturer',
        'manufacture_date',
        'poultry_feed_type_id',
        'created_by',
        'status'
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function feedType(): BelongsTo
    {
        return $this->belongsTo(PoultryFeedType::class, 'poultry_feed_type_id');
    }

    public function feedUsages(): HasMany
    {
        return $this->hasMany(PoultryFeedUsage::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Update inventory status based on quantity thresholds
     */
    public function updateStatusBasedOnQuantity()
    {
        $originalQuantity = $this->getOriginal('quantity');
        $currentQuantity = $this->quantity;
        
        // If quantity is 0, set status to depleted
        if ($currentQuantity <= 0) {
            $this->status = 'depleted';
        }
        // If quantity is down 80% or more from original, set status to in_use (low)
        elseif ($originalQuantity > 0 && $currentQuantity <= ($originalQuantity * 0.2)) {
            $this->status = 'in_use';
        }
        // If quantity is above 20% of original, set status to available
        elseif ($originalQuantity > 0 && $currentQuantity > ($originalQuantity * 0.2)) {
            $this->status = 'available';
        }
        
        // Save the status if it changed
        if ($this->isDirty('status')) {
            $this->save();
        }
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
} 