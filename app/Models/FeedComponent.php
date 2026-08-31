<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeedComponent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'name',
        'description',
        'unit',
        'crude_protein',
        'crude_fat',
        'crude_fiber',
        'calcium',
        'phosphorus',
        'metabolizable_energy',
        'moisture',
        'ash',
        'status',
        'created_by',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function compositions(): HasMany
    {
        return $this->hasMany(FeedComposition::class);
    }
}

