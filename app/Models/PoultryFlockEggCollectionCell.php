<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eggs and broken eggs from one cell in one collection round.
 * `birds` is a snapshot of the cell's bird count when recorded.
 */
class PoultryFlockEggCollectionCell extends Model
{
    protected $fillable = [
        'collection_id',
        'cell_id',
        'eggs',
        'broken',
        'birds',
    ];

    protected $casts = [
        'eggs' => 'integer',
        'broken' => 'integer',
        'birds' => 'integer',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(PoultryFlockEggCollection::class, 'collection_id');
    }

    public function cell(): BelongsTo
    {
        return $this->belongsTo(PoultryHouseCell::class, 'cell_id');
    }
}
