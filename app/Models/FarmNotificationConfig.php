<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmNotificationConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'default_task_reminders',
        'escalation_enabled',
        'escalate_to_manager_after_minutes',
        'escalate_high_priority_after_minutes',
        'notify_managers_on_completion',
        'notify_managers_on_overdue',
        'email_max_attempts',
    ];

    protected $casts = [
        'default_task_reminders' => 'array',
        'escalation_enabled' => 'boolean',
        'escalate_to_manager_after_minutes' => 'integer',
        'escalate_high_priority_after_minutes' => 'integer',
        'notify_managers_on_completion' => 'boolean',
        'notify_managers_on_overdue' => 'boolean',
        'email_max_attempts' => 'integer',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }
}
