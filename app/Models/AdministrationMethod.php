<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdministrationMethod extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the vaccine products that use this administration method.
     */
    public function vaccineProducts()
    {
        return $this->hasMany(PoultryVaccineProduct::class, 'administration_method_id');
    }

    /**
     * Get the schedule items that use this administration method.
     */
    public function scheduleItems()
    {
        return $this->hasMany(ScheduleItemAdministrationMethod::class, 'administration_method_id');
    }

    /**
     * Scope a query to only include active administration methods.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include inactive administration methods.
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Get the count of vaccine products using this method.
     */
    public function getVaccineProductsCountAttribute()
    {
        return $this->vaccineProducts()->count();
    }

    /**
     * Check if this administration method is being used by any vaccine products.
     */
    public function isInUse()
    {
        return $this->vaccineProducts()->exists();
    }

    /**
     * Get the usage statistics for this administration method.
     */
    public function getUsageStatistics()
    {
        return [
            'total_vaccine_products' => $this->vaccineProducts()->count(),
            'total_schedule_items' => $this->scheduleItems()->count(),
            'is_in_use' => $this->isInUse(),
        ];
    }
}
