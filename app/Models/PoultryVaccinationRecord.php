<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PoultryVaccinationRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'flock_id',
        'poultry_vaccine_id',
        'poultry_vaccine_inventory_id',
        'date',
        'administered_by',
        'dosage',
        'dosage_unit',
        'quantity',
        'cost',
        'notes',
        'administration_method_id',
    ];

    protected $casts = [
        'date' => 'date',
        'dosage' => 'integer',
        'quantity' => 'decimal:2',
        'cost' => 'decimal:2',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function flock()
    {
        return $this->belongsTo(Flock::class);
    }

    public function vaccine()
    {
        return $this->belongsTo(PoultryVaccine::class, 'poultry_vaccine_id');
    }

    public function vaccineInventory()
    {
        return $this->belongsTo(PoultryVaccineInventory::class, 'poultry_vaccine_inventory_id');
    }

    public function administrationMethod()
    {
        return $this->belongsTo(AdministrationMethod::class);
    }

    public function scopeForFarm($query, $farmId)
    {
        return $query->where('farm_id', $farmId);
    }

    public function scopeForFlock($query, $flockId)
    {
        return $query->where('flock_id', $flockId);
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function calculateTotalCost($startDate, $endDate)
    {
        return static::where('flock_id', $this->flock_id)
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('cost');
    }

    public function getVaccinationHistory($flockId)
    {
        return static::where('flock_id', $flockId)
            ->with(['vaccine', 'administrationMethod'])
            ->orderBy('date', 'desc')
            ->get();
    }

    public function wasVaccineAdministered($vaccineId, $startDate, $endDate)
    {
        return static::where('poultry_vaccine_id', $vaccineId)
            ->where('flock_id', $this->flock_id)
            ->whereBetween('date', [$startDate, $endDate])
            ->exists();
    }
} 