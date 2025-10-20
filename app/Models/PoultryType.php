<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PoultryType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'poultry_type_id',
        'description',
        'status',
        'farm_id',
        'created_by'
    ];

    public function flocks(): HasMany
    {
        return $this->hasMany(Flock::class);
    }

    public function poultryHouses(): HasMany
    {
        return $this->hasMany(PoultryHouse::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function feedTypes(): HasMany
    {
        return $this->hasMany(PoultryFeedType::class);
    }
} 