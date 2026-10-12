<?php

namespace App\Models;

use App\Support\MedicationQuantity;
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
        'last_restocked',
        'packs_received',
        'pack_size_base',
        'content_unit',
        'total_content',
        'depleted_at',
        'depleted_by',
        'depletion_reason',
        'depletion_note',
        'written_off_quantity',
        'written_off_value',
    ];

    protected $casts = [
        'packs_received' => 'float',
        'pack_size_base' => 'float',
        'total_content' => 'float',
        'written_off_quantity' => 'float',
        'written_off_value' => 'float',
        'depleted_at' => 'datetime',
    ];

    protected $appends = [
        'remaining_breakdown',
        'remaining_packs_equivalent',
    ];

    protected static function booted(): void
    {
        // `quantity` is decremented as stock is used, so it is the remaining amount;
        // keep `available_quantity` equal to it.
        static::saving(function (self $inventory) {
            if ($inventory->isDirty('quantity') || ! $inventory->exists) {
                $inventory->available_quantity = max(0, (float) $inventory->quantity);
            }
        });
    }

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

    public function depletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'depleted_by');
    }

    /** Batches recorded with a pack size store quantities in g, ml or tablet. */
    public function isUnitAware(): bool
    {
        return (bool) $this->content_unit && (float) $this->pack_size_base > 0;
    }

    /** Cost of an amount (in this batch's stock unit): per pack for unit-aware batches. */
    public function costOf(float $quantity): float
    {
        $unitCost = (float) $this->unit_cost;

        return $this->isUnitAware()
            ? round($quantity / (float) $this->pack_size_base * $unitCost, 2)
            : round($quantity * $unitCost, 2);
    }

    public function getRemainingBreakdownAttribute(): ?string
    {
        if (! $this->isUnitAware()) {
            return null;
        }

        return MedicationQuantity::breakdown(
            (float) $this->quantity,
            (float) $this->pack_size_base,
            $this->product?->pack_type,
            $this->content_unit
        )['label'];
    }

    /** Level compared with the product's minimum stock: packs for unit-aware batches, raw quantity otherwise. */
    public function stockLevel(): float
    {
        return $this->isUnitAware()
            ? (float) $this->quantity / (float) $this->pack_size_base
            : (float) $this->quantity;
    }

    public function getRemainingPacksEquivalentAttribute(): ?float
    {
        return $this->isUnitAware()
            ? round((float) $this->quantity / (float) $this->pack_size_base, 4)
            : null;
    }

    /**
     * Update the status based on current quantity
     */
    public function updateStatus()
    {
        $quantity = (float) $this->quantity;
        if ($quantity <= 0) {
            $this->status = 'depleted';
        } elseif ($this->isUnitAware()) {
            $this->status = $quantity < (float) $this->total_content ? 'in_use' : 'available';
        } elseif ($quantity <= 10) {
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
        return (float) $this->quantity + 1e-6 >= (float) $requiredQuantity;
    }
}
