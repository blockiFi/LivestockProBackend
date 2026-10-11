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
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AI_IMAGE = 'ai_image';
    public const SOURCE_AI_FILE = 'ai_file';
    public const SOURCE_SUMMARY_ONLY = 'summary_only';

    public const DATA_SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_AI_IMAGE,
        self::SOURCE_AI_FILE,
        self::SOURCE_SUMMARY_ONLY,
    ];

    public const SEXES = ['mixed', 'male', 'female'];

    public const WEIGHING_METHODS = ['manual_scale', 'hanging_scale', 'platform_auto', 'crate'];

    /** Uniformity from individual weights, from a farm-counted "birds within band", or estimated from min/max. */
    public const UNIFORMITY_MEASURED = 'measured';
    public const UNIFORMITY_COUNTED = 'counted';
    public const UNIFORMITY_ESTIMATED = 'estimated';

    protected $fillable = [
        'farm_id',
        'flock_id',
        'house_id',
        'pen_label',
        'sex',
        'weighing_method',
        'scale_reference',
        'average_weight',
        'min_weight',
        'max_weight',
        'number_of_birds',
        'sample_size',
        'report_date',
        'weighed_at_time',
        'age_days',
        'target_weight_g',
        'average_weight_g',
        'median_weight_g',
        'std_dev_g',
        'cv_percent',
        'uniformity_percent',
        'uniformity_band_percent',
        'birds_within_band',
        'uniformity_method',
        'deviation_from_target_g',
        'deviation_from_target_percent',
        'daily_gain_g',
        'data_source',
        'source_file_path',
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
        'age_days' => 'integer',
        'target_weight_g' => 'float',
        'average_weight_g' => 'float',
        'median_weight_g' => 'float',
        'std_dev_g' => 'float',
        'cv_percent' => 'float',
        'uniformity_percent' => 'float',
        'uniformity_band_percent' => 'float',
        'birds_within_band' => 'integer',
        'deviation_from_target_g' => 'float',
        'deviation_from_target_percent' => 'float',
        'daily_gain_g' => 'float',
    ];

    public function samples()
    {
        return $this->hasMany(PoultryFlockWeightSample::class, 'weight_report_id')->orderBy('sequence');
    }

    public function house()
    {
        return $this->belongsTo(PoultryHouse::class, 'house_id');
    }

    public function hasDetailedData(): bool
    {
        return ($this->data_source && $this->data_source !== self::SOURCE_SUMMARY_ONLY)
            || $this->samples()->exists();
    }

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
     * Get the user who recorded the report.
     */
    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @deprecated Use recordedBy() instead.
     */
    public function creator()
    {
        return $this->recordedBy();
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