<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FlockHouseAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'flock_id',
        'house_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(PoultryHouse::class, 'house_id');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }
}

