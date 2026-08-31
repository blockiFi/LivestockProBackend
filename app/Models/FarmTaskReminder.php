<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarmTaskReminder extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'schedule_id',
        'instance_id',
        'offset_minutes',
        'label',
        'is_active',
    ];

    protected $casts = [
        'offset_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['resolved_label'];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FarmTaskSchedule::class, 'schedule_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(FarmTaskInstance::class, 'instance_id');
    }

    public function scheduledNotifications(): HasMany
    {
        return $this->hasMany(ScheduledNotification::class, 'reminder_id');
    }

    public function getResolvedLabelAttribute(): string
    {
        return $this->label ?: self::describeOffset((int) $this->offset_minutes);
    }

    public static function describeOffset(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'At task time';
        }

        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days === 1 ? '1 day before' : $days . ' days before';
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 hour before' : $hours . ' hours before';
        }

        return $minutes . ' minutes before';
    }
}
