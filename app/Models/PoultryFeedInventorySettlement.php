<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoultryFeedInventorySettlement extends Model
{
    protected $fillable = [
        'from_inventory_id',
        'to_inventory_id',
        'usage_id',
        'source_usage_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'float',
    ];

    public function fromInventory(): BelongsTo
    {
        return $this->belongsTo(PoultryFeedInventory::class, 'from_inventory_id');
    }

    public function toInventory(): BelongsTo
    {
        return $this->belongsTo(PoultryFeedInventory::class, 'to_inventory_id');
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(PoultryFeedUsage::class, 'usage_id');
    }

    public function sourceUsage(): BelongsTo
    {
        return $this->belongsTo(PoultryFeedUsage::class, 'source_usage_id');
    }
}
