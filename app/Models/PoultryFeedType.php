<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoultryFeedType extends Model
{
    protected $fillable = [
        'name',
        'type',
        'poultry_type_id',
        'description',
        'status',
        'farm_id',
        'created_by'
    ];

    public function inventories(): HasMany
    {
        return $this->hasMany(PoultryFeedInventory::class);
    }

    public function feedUsages(): HasMany
    {
        return $this->hasMany(PoultryFeedUsage::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }
    public function poultryType(): BelongsTo
    {
        return $this->belongsTo(PoultryType::class);
    }
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
} 