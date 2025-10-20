<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PoultryFlockEggReport extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'farm_id',
        'flock_id',
        'eggs_collected',
        'average_egg_weight',
        'production_percentage',
        'bird_count',
        'notes',
        'report_date',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'report_date' => 'date',
        'average_egg_weight' => 'decimal:2',
        'production_percentage' => 'decimal:2',
    ];

    /**
     * Get the flock that owns the egg report.
     */
    public function flock()
    {
        return $this->belongsTo(Flock::class);
    }

    /**
     * Get the farm that owns the egg report.
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the user who created the report.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the report.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include reports for a specific farm.
     */
    public function scopeForFarm($query, $farmId)
    {
        return $query->where('farm_id', $farmId);
    }

    /**
     * Scope a query to only include reports for a specific flock.
     */
    public function scopeForFlock($query, $flockId)
    {
        return $query->where('flock_id', $flockId);
    }

    /**
     * Scope a query to only include reports for a specific date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('report_date', [$startDate, $endDate]);
    }

    /**
     * Calculate the total egg weight for this report.
     */
    public function calculateTotalEggWeight()
    {
        return $this->eggs_collected * $this->average_egg_weight;
    }

    /**
     * Calculate the eggs per bird for this report.
     */
    public function calculateEggsPerBird()
    {
        if ($this->bird_count === 0) {
            return null;
        }
        return $this->eggs_collected / $this->bird_count;
    }

    /**
     * Calculate the production trend since the last report.
     */
    public function calculateProductionTrend()
    {
        $previousReport = static::where('flock_id', $this->flock_id)
            ->where('report_date', '<', $this->report_date)
            ->orderBy('report_date', 'desc')
            ->first();

        if (!$previousReport) {
            return null;
        }

        return $this->production_percentage - $previousReport->production_percentage;
    }

    /**
     * Calculate the average daily production for a given period.
     */
    public function calculateAverageDailyProduction($startDate, $endDate)
    {
        $reports = static::where('flock_id', $this->flock_id)
            ->whereBetween('report_date', [$startDate, $endDate])
            ->get();

        if ($reports->isEmpty()) {
            return null;
        }

        $totalEggs = $reports->sum('eggs_collected');
        $days = $reports->count();

        return $totalEggs / $days;
    }
} 