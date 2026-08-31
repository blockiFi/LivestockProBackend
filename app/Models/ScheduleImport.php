<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleImport extends Model
{
    protected $fillable = [
        'farm_id',
        'created_by',
        'source_type',
        'source_path',
        'status',
        'feeding_layout',
        'feeding_layout_reason',
        'llm_provider',
        'llm_model',
        'llm_raw_response',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ScheduleImportItem::class);
    }
}

