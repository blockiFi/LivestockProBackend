<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoultryHouseCapacityRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'house_id',
        'min_age_days',
        'max_age_days',
        'capacity',
    ];

    protected $casts = [
        'min_age_days' => 'integer',
        'max_age_days' => 'integer',
        'capacity' => 'integer',
    ];

    public function house(): BelongsTo
    {
        return $this->belongsTo(PoultryHouse::class, 'house_id');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }
}

