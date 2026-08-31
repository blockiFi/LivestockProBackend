<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlockComparativeReport extends Model
{
    protected $fillable = [
        'farm_id',
        'flock_id',
        'poultry_type_id',
        'data_fingerprint',
        'report_payload',
        'ai_insights',
        'generated_by',
        'generated_at',
    ];

    protected $casts = [
        'report_payload' => 'array',
        'ai_insights' => 'array',
        'generated_at' => 'datetime',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    public function poultryType(): BelongsTo
    {
        return $this->belongsTo(PoultryType::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
