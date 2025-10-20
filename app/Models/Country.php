<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'iso_code',
        'currency_code',
        'currency_name',
        'currency_symbol',
        'status'
    ];

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class);
    }
} 