<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FlockStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'poultry_type_id',
        'from_age',
        'to_age',
    ];

    public function flocks(): HasMany
    {
        return $this->hasMany(Flock::class);
    }

    public function poultryType(): BelongsTo
    {
        return $this->belongsTo(PoultryType::class);
    }
} 