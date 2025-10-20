<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PoultryFlockWeightReport extends Model
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
        'average_weight',
        'min_weight',
        'max_weight',
        'number_of_birds',
        'sample_size',
        'report_date',
        'notes',
        'recorded_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'report_date' => 'date',
        'average_weight' => 'decimal:2',
        'min_weight' => 'decimal:2',
        'max_weight' => 'decimal:2',
    ];

    /**
     * Get the flock that owns the weight report.
     */
    public function flock()
    {
        return $this->belongsTo(Flock::class);
    }

    /**
     * Get the farm that owns the weight report.
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
     * Calculate the weight gain since the last report.
     */
    public function calculateWeightGain()
    {
        $previousReport = static::where('flock_id', $this->flock_id)
            ->where('report_date', '<', $this->report_date)
            ->orderBy('report_date', 'desc')
            ->first();

        if (!$previousReport) {
            return null;
        }

        return $this->average_weight - $previousReport->average_weight;
    }

    /**
     * Calculate the average daily gain since the last report.
     */
    public function calculateAverageDailyGain()
    {
        $previousReport = static::where('flock_id', $this->flock_id)
            ->where('report_date', '<', $this->report_date)
            ->orderBy('report_date', 'desc')
            ->first();

        if (!$previousReport) {
            return null;
        }

        $daysBetween = $this->report_date->diffInDays($previousReport->report_date);
        if ($daysBetween === 0) {
            return null;
        }

        return ($this->average_weight - $previousReport->average_weight) / $daysBetween;
    }
} 