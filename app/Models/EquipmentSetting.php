<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentSetting extends Model
{
    protected $fillable = [
        'farm_id',
        'asset_id_prefix',
        'asset_id_format',
        'warranty_reminder_days',
        'maintenance_reminder_days',
    ];

    protected $casts = [
        'warranty_reminder_days' => 'array',
        'maintenance_reminder_days' => 'array',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }
}
