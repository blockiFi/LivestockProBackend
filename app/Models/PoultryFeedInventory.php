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
        'created_by'
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

   
} 