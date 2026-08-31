<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledNotification extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'farm_id',
        'user_id',
        'reminder_id',
        'instance_id',
        'type',
        'offset_minutes',
        'scheduled_for',
        'status',
        'notification_id',
        'dedupe_key',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'processed_at' => 'datetime',
        'offset_minutes' => 'integer',
        'payload' => 'array',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(FarmTaskReminder::class, 'reminder_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(FarmTaskInstance::class, 'instance_id');
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    public function scopeDue(Builder $query, ?\DateTimeInterface $now = null): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('scheduled_for', '<=', $now ?? now());
    }
}
