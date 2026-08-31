<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentUsageLog extends Model
{
    protected $fillable = [
        'farm_id',
        'equipment_id',
        'metric',
        'value',
        'delta',
        'recorded_at',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'recorded_at' => 'date',
        'value' => 'decimal:2',
        'delta' => 'decimal:2',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
