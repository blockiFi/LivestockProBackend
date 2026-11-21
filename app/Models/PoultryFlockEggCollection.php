<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One collection round within a daily egg report. Either a plain total or per-cell counts.
 */
class PoultryFlockEggCollection extends Model
{
    public const MODE_TOTAL = 'total';

    public const MODE_CELL = 'cell';

    protected $fillable = [
        'egg_report_id',
        'round_no',
        'collected_at',
        'entry_mode',
        'source',
        'eggs_collected',
        'eggs_broken_cells',
        'eggs_broken_extra',
        'average_egg_weight',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'round_no' => 'integer',
        'eggs_collected' => 'integer',
        'eggs_broken_cells' => 'integer',
        'eggs_broken_extra' => 'integer',
        'average_egg_weight' => 'decimal:2',
    ];

    protected $appends = ['eggs_broken'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(PoultryFlockEggReport::class, 'egg_report_id');
    }

    public function cells(): HasMany
    {
        return $this->hasMany(PoultryFlockEggCollectionCell::class, 'collection_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getEggsBrokenAttribute(): int
    {
        return (int) $this->eggs_broken_cells + (int) $this->eggs_broken_extra;
    }
}
