<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Farm extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
        'country_id',
        'state',
        'city',
        'status',
        'postal_code',
        'website',
        'logo',
        'established_date',
        'size_hectares',
        'registration_number',
        'created_by'
    ];

    public function vaccines(): HasMany
    {
        return $this->hasMany(PoultryVaccine::class)->orWhere('type', 'default');
    }
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'farm_users');
    }

    public function poultryHouses(): HasMany
    {
        return $this->hasMany(PoultryHouse::class);
    }

    public function flocks(): HasMany
    {
        return $this->hasMany(Flock::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function poultryFeedUsages(): HasMany
    {
        return $this->hasMany(PoultryFeedUsage::class);
    }

    public function poultryMedicationRecords(): HasMany
    {
        return $this->hasMany(PoultryMedicationRecord::class);
    }

    public function poultryVaccineInventories(): HasMany
    {
        return $this->hasMany(PoultryVaccineInventory::class);
    }

    public function poultryMedicationInventories(): HasMany
    {
        return $this->hasMany(PoultryMedicationInventory::class);
    }

    public function poultryFeedInventories(): HasMany
    {
        return $this->hasMany(PoultryFeedInventory::class);
    }

    public function batchSchedules(): HasMany
    {
        return $this->hasMany(BatchSchedule::class);
    }
    public function permissions(): HasMany
    {
        return $this->hasManyThrough(Permission::class, ModelHasPermission::class, 'farm_id', 'id', 'id', 'permission_id');
    }
    public function roles(): HasManyThrough
    {
        return $this->hasManyThrough(Role::class, ModelHasRole::class, 'farm_id', 'id', 'id', 'role_id');
    }
    public function salesRecords(): HasMany
    {
        return $this->hasMany(SalesRecord::class);
    }
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class)->where('type', 'default')->orWhere('farm_id', $this->id);
    }
    public function medicationProducts(): HasMany
    {
        return $this->hasMany(MedicationProduct::class);
    }
    public function poultryVaccineProducts(): HasMany
    {
        return $this->hasMany(PoultryVaccineProduct::class);
    }
    public function poultryEvents(): HasMany
    {
        return $this->hasMany(PoultryEvent::class);
    }
    public function poultryFlockEggReports(): HasMany
    {
        return $this->hasMany(PoultryFlockEggReport::class);
    }
    public function poultryFlockWeightReports(): HasMany
    {
        return $this->hasMany(PoultryFlockWeightReport::class);
    }
    public function poultryMortalityReports(): HasMany
    {
        return $this->hasMany(PoultryMortalityReport::class);
    }

} 