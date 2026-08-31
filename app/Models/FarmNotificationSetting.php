<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmNotificationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'type',
        'enabled',
        'mandatory',
        'default_in_app',
        'default_email',
        'priority',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'mandatory' => 'boolean',
        'default_in_app' => 'boolean',
        'default_email' => 'boolean',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }
}
