<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PoultryMedicationRecord extends Model
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
        'poultry_medication_id',
        'poultry_medication_inventory_id',
        'date',
        'administered_by',
        'dosage',
        'dosage_unit',
        'quantity',
        'cost',
        'notes',
        'administration_method_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
        'dosage' => 'integer',
        'quantity' => 'decimal:2',
        'cost' => 'decimal:2',
    ];

    /**
     * Get the farm that owns the medication record.
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the flock that owns the medication record.
     */
    public function flock()
    {
        return $this->belongsTo(Flock::class);
    }

    /**
     * Get the medication that was administered.
     */
    public function medication()
    {
        return $this->belongsTo(PoultryMedication::class, 'poultry_medication_id');
    }

    /**
     * Get the medication inventory record.
     */
    public function medicationInventory()
    {
        return $this->belongsTo(PoultryMedicationInventory::class, 'poultry_medication_inventory_id');
    }

    /**
     * Get the administration method.
     */
    public function administrationMethod()
    {
        return $this->belongsTo(AdministrationMethod::class);
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
     * Calculate the total cost of medication for a given period.
     */
    public function calculateTotalCost($startDate, $endDate)
    {
        return static::where('flock_id', $this->flock_id)
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('cost');
    }

    /**
     * Get the medication history for a flock.
     */
    public function getMedicationHistory($flockId)
    {
        return static::where('flock_id', $flockId)
            ->with(['medication', 'administrationMethod'])
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * Check if a medication was administered within a specific period.
     */
    public function wasMedicationAdministered($medicationId, $startDate, $endDate)
    {
        return static::where('poultry_medication_id', $medicationId)
            ->where('flock_id', $this->flock_id)
            ->whereBetween('date', [$startDate, $endDate])
            ->exists();
    }
}
