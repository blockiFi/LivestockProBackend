<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoultryVaccineProduct extends Model
{
    protected $fillable = [
        'name',
        'description',
        'manufacturer',
        'status',
        'farm_id',
        'type',
        'poultry_vaccine_id',
        'administration_method_id',
        'withdrawal_period',
        'withdrawal_period_unit',
        'dosage',
        'dosage_unit',
        'image_url',
        'created_by',
        'min_stock_level',
    ];

    public function inventories(): HasMany
    {
        return $this->hasMany(PoultryVaccineInventory::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scheduleItems(): HasMany
    {
        return $this->hasMany(ScheduleItem::class);
    }
    public function vaccine(): BelongsTo
    {
        return $this->belongsTo(PoultryVaccine::class, 'poultry_vaccine_id');
    }
public function administrationMethod(): BelongsTo
{
    return $this->belongsTo(AdministrationMethod::class);
}
}