<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\PoultryHouseCapacityRule;

class PoultryHouse extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'name',
        'poultry_type_id',
        'liter_type',
        'capacity',
        'dimensions',
        'construction_date',
        'last_maintenance_date',
        'liter_type_id',
        'status',
        'notes'
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function flocks(): HasMany
    {
        return $this->hasMany(Flock::class);
    }

    public function poultryEvents(): HasMany
    {
        return $this->hasMany(PoultryEvent::class)->where('eventable_type', PoultryHouse::class);
    }

    public function poultryFlockEggReports(): HasMany
    {
        return $this->hasMany(PoultryFlockEggReport::class);
    }

    public function poultryFlockWeightReports(): HasMany
    {
        return $this->hasMany(PoultryFlockWeightReport::class);
    }

    public function poultryMortalityReports(): HasMany
    {
        return $this->hasMany(PoultryMortalityReport::class);
    }
    public function poultryType(): BelongsTo
    {
        return $this->belongsTo(PoultryType::class);
    }

    public function literType(): BelongsTo
    {
        return $this->belongsTo(LiterType::class);
    }

    public function capacityRules(): HasMany
    {
        return $this->hasMany(PoultryHouseCapacityRule::class, 'house_id')
            ->orderBy('min_age_days');
    }
} 