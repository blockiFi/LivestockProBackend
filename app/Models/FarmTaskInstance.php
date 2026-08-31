<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FarmTaskInstance extends Model
{
    protected $fillable = [
        'farm_id',
        'schedule_id',
        'title',
        'description',
        'section',
        'priority',
        'instructions',
        'notes',
        'scheduled_date',
        'start_time',
        'due_time',
        'status',
        'assigned_to_user_id',
        'animal_group',
        'medication_name',
        'dosage_instructions',
        'require_completion_confirmation',
        'require_supervisor_approval',
        'require_signature',
        'awaiting_approval',
        'started_at',
        'started_by',
        'occurrence_index',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'require_completion_confirmation' => 'boolean',
        'require_supervisor_approval' => 'boolean',
        'require_signature' => 'boolean',
        'awaiting_approval' => 'boolean',
        'started_at' => 'datetime',
        'occurrence_index' => 'integer',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FarmTaskSchedule::class, 'schedule_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'farm_task_instance_assignees', 'instance_id', 'user_id')
            ->withTimestamps();
    }

    public function completion(): HasOne
    {
        return $this->hasOne(FarmTaskCompletion::class, 'instance_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(FarmTaskReminder::class, 'instance_id')->orderBy('offset_minutes', 'desc');
    }

    public function scheduledNotifications(): HasMany
    {
        return $this->hasMany(ScheduledNotification::class, 'instance_id');
    }

    /**
     * Combined date + time the task is expected to start, in farm-local terms.
     */
    public function scheduledStartAt(?string $timezone = null): CarbonImmutable
    {
        $time = $this->start_time ?: $this->due_time;
        $date = CarbonImmutable::parse($this->scheduled_date)->toDateString();

        return CarbonImmutable::parse(
            $time ? $date . ' ' . $time : $date . ' 00:00:00',
            $timezone ?: config('app.timezone', 'UTC')
        );
    }

    public function scheduledDueAt(?string $timezone = null): CarbonImmutable
    {
        $time = $this->due_time ?: $this->start_time;
        $date = CarbonImmutable::parse($this->scheduled_date)->toDateString();

        return CarbonImmutable::parse(
            $time ? $date . ' ' . $time : $date . ' 23:59:59',
            $timezone ?: config('app.timezone', 'UTC')
        );
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'in_progress', 'overdue'], true);
    }
}
