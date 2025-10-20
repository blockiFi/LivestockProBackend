<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PoultryFeedUsage extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'farm_id',
        'poultry_feed_inventory_id',
        'poultry_feed_type_id',
        'flock_id',
        'quantity',
        'unit_cost',
        'usage_date',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'usage_date' => 'date',
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
    ];

    /**
     * Get the farm that owns the feed usage.
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the feed inventory that owns the feed usage.
     */
    public function feedInventory()
    {
        return $this->belongsTo(PoultryFeedInventory::class, 'poultry_feed_inventory_id');
    }

    /**
     * Get the feed type that owns the feed usage.
     */
    public function feedType()
    {
        return $this->belongsTo(PoultryFeedType::class, 'poultry_feed_type_id');
    }

    /**
     * Get the flock that owns the feed usage.
     */
    public function flock()
    {
        return $this->belongsTo(Flock::class);
    }

    /**
     * Get the country that owns the feed usage.
     */
    public function country()
    {
        return $this->belongsTo(Country::class, 'countries_id');
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
        return $query->whereBetween('usage_date', [$startDate, $endDate]);
    }

    /**
     * Calculate the total cost for this feed usage.
     */
    public function calculateTotalCost()
    {
        return $this->quantity * $this->unit_cost;
    }

    /**
     * Calculate the feed conversion ratio (FCR) for a given period.
     */
    public function calculateFeedConversionRatio($startDate, $endDate)
    {
        $totalFeedUsed = static::where('flock_id', $this->flock_id)
            ->whereBetween('usage_date', [$startDate, $endDate])
            ->sum('quantity');

        // Get the weight gain for the same period from FlockDailyRecord
        $weightGain = FlockDailyRecord::where('flock_id', $this->flock_id)
            ->whereBetween('record_date', [$startDate, $endDate])
            ->max('average_weight') - 
            FlockDailyRecord::where('flock_id', $this->flock_id)
            ->whereBetween('record_date', [$startDate, $endDate])
            ->min('average_weight');

        if ($weightGain <= 0) {
            return null;
        }

        return $totalFeedUsed / $weightGain;
    }

    /**
     * Calculate the average daily feed consumption for a given period.
     */
    public function calculateAverageDailyConsumption($startDate, $endDate)
    {
        $totalFeed = static::where('flock_id', $this->flock_id)
            ->whereBetween('usage_date', [$startDate, $endDate])
            ->sum('quantity');

        $days = $this->usage_date->diffInDays($startDate) + 1;
        if ($days === 0) {
            return null;
        }

        return $totalFeed / $days;
    }
} 