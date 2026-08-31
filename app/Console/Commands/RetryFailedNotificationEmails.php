<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationEmail;
use App\Models\NotificationDelivery;
use App\Notifications\DeliveryStatus;
use App\Notifications\NotificationChannel;
use Illuminate\Console\Command;

/**
 * Safety net for retries whose queued job was lost (worker restart, crash).
 *
 * Deliveries that exhausted their attempts stay failed and are never retried
 * again, so this cannot loop forever.
 */
class RetryFailedNotificationEmails extends Command
{
    protected $signature = 'notifications:retry-emails
                            {--limit=200 : Maximum deliveries to requeue in one pass}';

    protected $description = 'Requeue notification emails that are due for another delivery attempt';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $deliveries = NotificationDelivery::query()
            ->where('channel', NotificationChannel::EMAIL)
            ->whereIn('status', [DeliveryStatus::RETRYING, DeliveryStatus::PENDING])
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->whereColumn('attempts', '<', 'max_attempts')
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();

        foreach ($deliveries as $delivery) {
            $delivery->markQueued();
            SendNotificationEmail::dispatch($delivery->id)->onQueue(config('notifications.queue'));
        }

        $this->info("Requeued {$deliveries->count()} notification email(s).");

        return self::SUCCESS;
    }
}
