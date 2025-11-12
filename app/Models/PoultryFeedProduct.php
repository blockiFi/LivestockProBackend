<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PoultryFeedProduct extends Model
{
    protected $fillable = [
        'name',
        'sku',
        'description',
        'unit',
        'price',
        'poultry_feed_type_id',
        'farm_id',
        'created_by',
        'status'
    ];

    public function feedType(): BelongsTo
    {
        return $this->belongsTo(PoultryFeedType::class, 'poultry_feed_type_id');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(PoultryFeedInventory::class, 'poultry_feed_product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
