<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Services\Notifications\TaskReminderService;
use Illuminate\Console\Command;

/**
 * Materialises reminder occurrences for upcoming task instances.
 *
 * Instances created outside the normal generator path (imports, backfills) are
 * picked up here, and re-running never produces duplicates.
 */
class GenerateNotificationReminders extends Command
{
    protected $signature = 'notifications:generate-reminders
                            {--farm= : Only process one farm}
                            {--days= : How many days ahead to materialise}';

    protected $description = 'Create pending reminder occurrences for upcoming farm task instances';

    public function handle(TaskReminderService $reminders): int
    {
        $farmId = $this->option('farm');
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;

        $farms = $farmId
            ? Farm::where('id', $farmId)->get(['id', 'name'])
            : Farm::query()->get(['id', 'name']);

        $total = 0;

        foreach ($farms as $farm) {
            $created = $reminders->materializeForFarm((int) $farm->id, $days);
            $total += $created;

            if ($created > 0) {
                $this->line("Farm {$farm->id}: materialised {$created} reminder(s)");
            }
        }

        $this->info("Done. Created {$total} reminder occurrence(s).");

        return self::SUCCESS;
    }
}
