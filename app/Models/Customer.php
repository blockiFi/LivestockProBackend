<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'farm_id'
    ];

    public function salesRecords(): HasMany
    {
        return $this->hasMany(SalesRecord::class);
    }
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
} 