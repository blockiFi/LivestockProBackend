<?php

namespace App\Models;

use App\Notifications\DeliveryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_id',
        'channel',
        'status',
        'attempts',
        'max_attempts',
        'target',
        'error',
        'queued_at',
        'sent_at',
        'delivered_at',
        'failed_at',
        'next_attempt_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < $this->max_attempts;
    }

    public function markQueued(): void
    {
        $this->forceFill([
            'status' => DeliveryStatus::QUEUED,
            'queued_at' => $this->queued_at ?? now(),
            'next_attempt_at' => null,
        ])->save();
    }

    public function markSent(): void
    {
        $this->forceFill([
            'status' => DeliveryStatus::SENT,
            'attempts' => $this->attempts + 1,
            'sent_at' => now(),
            'delivered_at' => now(),
            'error' => null,
            'next_attempt_at' => null,
        ])->save();
    }

    /**
     * Records a failed attempt, scheduling a retry while attempts remain.
     */
    public function markAttemptFailed(string $error, ?int $retryInMinutes): void
    {
        $attempts = $this->attempts + 1;
        $canRetry = $attempts < $this->max_attempts && $retryInMinutes !== null;

        $this->forceFill([
            'status' => $canRetry ? DeliveryStatus::RETRYING : DeliveryStatus::FAILED,
            'attempts' => $attempts,
            'error' => mb_substr($error, 0, 2000),
            'failed_at' => $canRetry ? null : now(),
            'next_attempt_at' => $canRetry ? now()->addMinutes($retryInMinutes) : null,
        ])->save();
    }
}
