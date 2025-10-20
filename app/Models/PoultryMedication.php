<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PoultryMedication extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'type',
        'name',
        'description',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(MedicationProduct::class);
    }

    public function medicationRecords(): HasMany
    {
        return $this->hasMany(PoultryMedicationRecord::class);
    }
} 