<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PoultryMortalityReport extends Model
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
        'poultry_type_id',
        'date',
        'mortality_count',
        'bird_count',
        'mortality_percentage',
        'notes',
        'recorded_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'mortality_percentage' => 'decimal:2'
    ];

    /**
     * Get the flock that owns the mortality report.
     */
    public function flock()
    {
        return $this->belongsTo(Flock::class);
    }

    /**
     * Get the farm that owns the mortality report.
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the poultry type that owns the mortality report.
     */
    public function poultryType()
    {
        return $this->belongsTo(PoultryType::class);
    }

    /**
     * Get the user who created the report.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'recorded_by');
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
     * Scope a query to only include reports with high mortality rates.
     */
    public function scopeHighMortality($query, $threshold = 5.0)
    {
        return $query->where('mortality_percentage', '>', $threshold);
    }
}
