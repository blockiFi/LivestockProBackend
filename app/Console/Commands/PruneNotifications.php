<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\ScheduledNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune
                            {--days= : Retention window in days}';

    protected $description = 'Remove notification history and processed reminders older than the retention window';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('notifications.retention_days', 180);

        $cutoff = CarbonImmutable::now()->subDays(max(1, $days));

        // Unread notifications are kept regardless of age so nothing is missed.
        $notifications = Notification::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('read_at')
            ->delete();

        $reminders = ScheduledNotification::query()
            ->where('scheduled_for', '<', $cutoff)
            ->whereIn('status', [
                ScheduledNotification::STATUS_SENT,
                ScheduledNotification::STATUS_SKIPPED,
                ScheduledNotification::STATUS_CANCELLED,
            ])
            ->delete();

        $this->info("Pruned {$notifications} notification(s) and {$reminders} reminder occurrence(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
