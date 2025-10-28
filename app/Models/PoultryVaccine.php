<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoultryVaccine extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'farm_id',
        'description',
        'type',
        'administration_age',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'type' => 'string',
        'administration_age' => 'integer',
    ];

    /**
     * The possible types of vaccines.
     *
     * @var array<string>
     */
    public const TYPES = [
        'default' => 'Default',
        'user' => 'User',
    ];

    /**
     * Get the farm that owns the vaccine.
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the vaccination records for this vaccine.
     */
    public function vaccinationRecords()
    {
        return $this->hasMany(PoultryVaccinationRecord::class);
    }

    /**
     * Scope a query to only include default vaccines.
     */
    public function scopeDefault($query)
    {
        return $query->where('type', 'default');
    }

    /**
     * Scope a query to only include user-created vaccines.
     */
    public function scopeUser($query)
    {
        return $query->where('type', 'user');
    }

    /**
     * Scope a query to only include records for a specific farm.
     */
    public function scopeForFarm($query, $farmId)
    {
        return $query->where('farm_id', $farmId);
    }

    /**
     * Get the total number of times this vaccine has been administered.
     */
    public function getTotalAdministrations()
    {
        return $this->vaccinationRecords()->count();
    }

    /**
     * Get the last date this vaccine was administered.
     */
    public function getLastAdministrationDate()
    {
        return $this->vaccinationRecords()
            ->latest('date')
            ->first()?->date;
    }

    /**
     * Check if this is a default vaccine.
     */
    public function isDefault()
    {
        return $this->type === 'default';
    }

    /**
     * Check if this is a user-created vaccine.
     */
    public function isUser()
    {
        return $this->type === 'user';
    }
    
    public function products(): HasMany
    {
        return $this->hasMany(PoultryVaccineProduct::class);
    }

    
}
