<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lettered row of cages in a poultry house (A, B, C...).
 */
class PoultryHouseRow extends Model
{
    protected $fillable = [
        'farm_id',
        'house_id',
        'code',
        'cell_count',
        'birds_per_cell',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'cell_count' => 'integer',
        'birds_per_cell' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function house(): BelongsTo
    {
        return $this->belongsTo(PoultryHouse::class, 'house_id');
    }

    public function cells(): HasMany
    {
        return $this->hasMany(PoultryHouseCell::class, 'row_id')->orderBy('cell_number');
    }

    /** Birds per cell for this row: the row override, else the house setting. */
    public function effectiveBirdsPerCell(): int
    {
        return (int) ($this->birds_per_cell ?? $this->house?->birds_per_cell ?? 1);
    }
}
