<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'theme',
        'locale',
        'timezone',
        'date_format',
        'notify_schedules',
        'notify_low_stock',
        'notify_mortality',
    ];

    protected $casts = [
        'notify_schedules' => 'boolean',
        'notify_low_stock' => 'boolean',
        'notify_mortality' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
