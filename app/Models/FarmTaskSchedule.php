<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarmTaskSchedule extends Model
{
    protected $fillable = [
        'farm_id',
        'template_id',
        'title',
        'description',
        'section',
        'priority',
        'instructions',
        'notes',
        'start_date',
        'end_date',
        'indefinite',
        'start_time',
        'due_time',
        'recurrence',
        'repeat_interval',
        'days_of_week',
        'month_day',
        'assignment_mode',
        'animal_group',
        'medication_name',
        'dosage_instructions',
        'require_completion_confirmation',
        'require_supervisor_approval',
        'require_signature',
        'is_active',
        'reminders_enabled',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'indefinite' => 'boolean',
        'days_of_week' => 'array',
        'require_completion_confirmation' => 'boolean',
        'require_supervisor_approval' => 'boolean',
        'require_signature' => 'boolean',
        'is_active' => 'boolean',
        'reminders_enabled' => 'boolean',
        'repeat_interval' => 'integer',
        'month_day' => 'integer',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FarmTaskTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignees(): HasMany
    {
        return $this->hasMany(FarmTaskScheduleAssignee::class, 'schedule_id')->orderBy('sort_order');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(FarmTaskInstance::class, 'schedule_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(FarmTaskReminder::class, 'schedule_id')->orderBy('offset_minutes', 'desc');
    }
}
