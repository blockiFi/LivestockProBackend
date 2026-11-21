<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One cage in a row (A1, A2...). Holds a number of birds and may be assigned to a flock.
 */
class PoultryHouseCell extends Model
{
    protected $fillable = [
        'row_id',
        'cell_number',
        'birds',
        'flock_id',
        'is_active',
    ];

    protected $casts = [
        'cell_number' => 'integer',
        'birds' => 'integer',
        'flock_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function row(): BelongsTo
    {
        return $this->belongsTo(PoultryHouseRow::class, 'row_id');
    }

    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    public function collectionCells(): HasMany
    {
        return $this->hasMany(PoultryFlockEggCollectionCell::class, 'cell_id');
    }
}
