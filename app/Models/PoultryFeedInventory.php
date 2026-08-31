<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PoultryFeedInventory extends Model
{
    protected $fillable = [
        'quantity',
        'available_quantity',
        'damaged_quantity',
        'closed_at',
        'closed_by',
        'close_notes',
        'allocated_flock_id',
        'unit_cost',
        'expiry_date',
        'batch_number',
        'farm_id',
        'manufacturer',
        'manufacture_date',
        'poultry_feed_type_id',
        'poultry_feed_product_id',
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

    public function feedProduct(): BelongsTo
    {
        return $this->belongsTo(PoultryFeedProduct::class, 'poultry_feed_product_id');
    }

    public function feedUsages(): HasMany
    {
        return $this->hasMany(PoultryFeedUsage::class, 'poultry_feed_inventory_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function allocatedFlock(): BelongsTo
    {
        return $this->belongsTo(Flock::class, 'allocated_flock_id');
    }

    /**
     * Update inventory status based on quantity thresholds.
     * Automatically closes the batch when remaining quantity reaches zero.
     */
    public function updateStatusBasedOnQuantity(): void
    {
        $currentQuantity = (float) $this->quantity;

        if ($this->status === 'closed') {
            if ($currentQuantity > 0) {
                $this->reopenFromAutoClose();
            }
            return;
        }

        if ($currentQuantity < 0) {
            $this->status = 'depleted';
        } elseif ($currentQuantity == 0.0) {
            $this->applyAutoCloseAsFullyUsed();
            return;
        } else {
            $originalQuantity = (float) $this->getOriginal('quantity');

            if ($originalQuantity > 0 && $currentQuantity <= ($originalQuantity * 0.2)) {
                $this->status = 'in_use';
            } elseif ($originalQuantity > 0 && $currentQuantity > ($originalQuantity * 0.2)) {
                $this->status = 'available';
            }
        }

        if ($this->isDirty('status')) {
            $this->save();
        }
    }

    protected function applyAutoCloseAsFullyUsed(): void
    {
        $this->status = 'closed';
        $this->closed_at = $this->closed_at ?? now();
        $this->close_notes = $this->close_notes ?? 'Automatically closed — stock fully used';
        $this->save();
    }

    protected function reopenFromAutoClose(): void
    {
        $this->status = 'available';
        $this->closed_at = null;
        $this->closed_by = null;
        $this->close_notes = null;
        $this->save();
        $this->updateStatusBasedOnQuantity();
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