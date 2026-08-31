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
        // Canonical columns
        'mortality_count',
        'culling_count',
        'average_weight_kg',
        'feed_consumption_kg',
        'water_consumption_liters',
        'egg_production_count',
        'egg_weight_grams',
        'temperature_celsius',
        'humidity_percentage',
        // Legacy columns still read by the frontend
        'mortality',
        'culls',
        'feed_consumed_kg',
        'water_consumed_liters',
        'avg_weight_grams',
        'min_temperature',
        'max_temperature',
        'humidity',
        'light_hours',
        'eggs_collected',
        'eggs_broken',
        'notes',
        'additional_data',
        'recorded_by',
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

    /**
     * Map canonical + legacy columns to the field names the frontend reads.
     */
    public function toFrontendArray(): array
    {
        $data = $this->toArray();
        $additional = is_array($this->additional_data) ? $this->additional_data : [];

        $avgWeightGrams = $this->avg_weight_grams;
        if (($avgWeightGrams === null || (float) $avgWeightGrams <= 0) && $this->average_weight_kg) {
            $avgWeightGrams = (float) $this->average_weight_kg * 1000;
        }

        return array_merge($data, [
            'mortality' => (int) ($this->mortality_count ?? $this->mortality ?? 0),
            'culls' => (int) ($this->culling_count ?? $this->culls ?? 0),
            'feed_consumed_kg' => (float) ($this->feed_consumption_kg ?? $this->feed_consumed_kg ?? 0),
            'water_consumed_liters' => (float) ($this->water_consumption_liters ?? $this->water_consumed_liters ?? 0),
            'avg_weight_grams' => $avgWeightGrams !== null ? (float) $avgWeightGrams : 0,
            'min_weight_grams' => (float) ($additional['min_weight_grams'] ?? 0),
            'max_weight_grams' => (float) ($additional['max_weight_grams'] ?? 0),
            'sample_size' => (int) ($additional['sample_size'] ?? 0),
            'humidity' => (float) ($this->humidity_percentage ?? $this->humidity ?? $additional['humidity'] ?? 0),
            'min_temperature' => $additional['min_temperature'] ?? $this->min_temperature ?? $this->temperature_celsius,
            'max_temperature' => $additional['max_temperature'] ?? $this->max_temperature ?? $this->temperature_celsius,
            'light_hours' => $additional['light_hours'] ?? $this->light_hours,
            'eggs_collected' => (int) ($this->egg_production_count ?? $this->eggs_collected ?? 0),
            'eggs_broken' => (int) ($additional['eggs_broken'] ?? $this->eggs_broken ?? 0),
        ]);
    }
}
