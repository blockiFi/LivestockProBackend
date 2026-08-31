<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\NotificationDelivery;
use App\Notifications\DeliveryStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers one notification email.
 *
 * Retries are tracked on the delivery row rather than relying on the queue's
 * own attempt counter, so the retry history survives worker restarts and is
 * visible to administrators.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue(config('notifications.queue'));
    }

    public function handle(): void
    {
        $delivery = NotificationDelivery::with('notification.user')->find($this->deliveryId);

        if (!$delivery) {
            return;
        }

        if (in_array($delivery->status, [DeliveryStatus::FAILED, DeliveryStatus::CANCELLED, DeliveryStatus::SENT, DeliveryStatus::DELIVERED], true)) {
            return;
        }

        $notification = $delivery->notification;
        $recipient = $notification?->user;

        if (!$notification || !$recipient || blank($recipient->email)) {
            $delivery->forceFill([
                'status' => DeliveryStatus::CANCELLED,
                'error' => 'No deliverable email address for recipient.',
                'failed_at' => now(),
            ])->save();

            return;
        }

        $delivery->forceFill(['status' => DeliveryStatus::PROCESSING])->save();

        try {
            Mail::to($recipient->email, $recipient->name)->send(new NotificationMail($notification));
            $delivery->markSent();
        } catch (\Throwable $e) {
            $this->handleFailure($delivery, $e);
        }
    }

    protected function handleFailure(NotificationDelivery $delivery, \Throwable $e): void
    {
        $backoff = config('notifications.email.retry_backoff_minutes', [1, 5, 15]);
        $nextIndex = $delivery->attempts; // attempts is pre-increment here
        $retryMinutes = $backoff[$nextIndex] ?? null;

        $delivery->markAttemptFailed($e->getMessage(), $retryMinutes);

        if ($delivery->status === DeliveryStatus::RETRYING) {
            self::dispatch($delivery->id)
                ->onQueue(config('notifications.queue'))
                ->delay(now()->addMinutes((int) $retryMinutes));

            return;
        }

        Log::error('Notification email permanently failed', [
            'delivery_id' => $delivery->id,
            'notification_id' => $delivery->notification_id,
            'attempts' => $delivery->attempts,
            'error' => $e->getMessage(),
        ]);
    }

    public function failed(?\Throwable $e): void
    {
        $delivery = NotificationDelivery::find($this->deliveryId);

        if ($delivery && !DeliveryStatus::isTerminal($delivery->status)) {
            $delivery->markAttemptFailed($e?->getMessage() ?? 'Queue worker failure', null);
        }
    }
}
