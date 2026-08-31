<?php

namespace App\Console\Commands;

use App\Services\Notifications\TaskReminderService;
use Illuminate\Console\Command;

/**
 * The notification scheduling engine sweep.
 *
 * Safe to run as often as every minute: each reminder occurrence carries a
 * dedupe key and is marked processed once fired.
 */
class ProcessDueNotifications extends Command
{
    protected $signature = 'notifications:process-reminders
                            {--farm= : Only process reminders for one farm}';

    protected $description = 'Send scheduled notifications (task reminders) whose time has come';

    public function handle(TaskReminderService $reminders): int
    {
        $farmId = $this->option('farm') ? (int) $this->option('farm') : null;

        $result = $reminders->processDue(null, $farmId);

        $this->info(sprintf(
            'Reminders processed: %d sent, %d skipped.',
            $result['sent'],
            $result['skipped']
        ));

        return self::SUCCESS;
    }
}
