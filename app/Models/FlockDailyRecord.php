<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlockDailyRecord extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'flock_id',
        'farm_id',
        'house_id',
        'date',
        'age_days',
        'total_birds',
        'mortality_count',
        'culling_count',
        'average_weight_kg',
        'feed_consumption_kg',
        'water_consumption_liters',
        'egg_production_count',
        'egg_weight_grams',
        'temperature_celsius',
        'humidity_percentage',
        'notes',
        'additional_data',
        'recorded_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
        'additional_data' => 'array',
        'average_weight_kg' => 'decimal:2',
        'feed_consumption_kg' => 'decimal:2',
        'water_consumption_liters' => 'decimal:2',
        'egg_production_count' => 'decimal:2',
        'egg_weight_grams' => 'decimal:2',
        'temperature_celsius' => 'decimal:1',
        'humidity_percentage' => 'decimal:1',
    ];

    /**
     * Get the flock that owns the daily record.
     */
    public function flock()
    {
        return $this->belongsTo(Flock::class);
    }

    /**
     * Get the farm that owns the daily record.
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the house that owns the daily record.
     */
    public function house()
    {
        return $this->belongsTo(PoultryHouse::class, 'house_id');
    }

    /**
     * Get the user who created the record.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the record.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include records for a specific farm.
     */
    public function scopeForFarm($query, $farmId)
    {
        return $query->where('farm_id', $farmId);
    }

    /**
     * Scope a query to only include records for a specific flock.
     */
    public function scopeForFlock($query, $flockId)
    {
        return $query->where('flock_id', $flockId);
    }

    /**
     * Scope a query to only include records for a specific date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }
}
