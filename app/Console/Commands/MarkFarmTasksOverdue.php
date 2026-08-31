<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Services\TaskInstanceGenerator;
use Illuminate\Console\Command;

class MarkFarmTasksOverdue extends Command
{
    protected $signature = 'farm-tasks:mark-overdue {--farm= : Specific farm id}';

    protected $description = 'Mark pending/in-progress farm tasks as overdue when past due';

    public function handle(TaskInstanceGenerator $generator): int
    {
        $farmId = $this->option('farm');
        $farms = $farmId
            ? Farm::where('id', $farmId)->get()
            : Farm::query()->get(['id', 'name']);

        $total = 0;
        foreach ($farms as $farm) {
            $n = $generator->markOverdueForFarm((int) $farm->id);
            $total += $n;
            $this->line("Farm {$farm->id}: marked {$n} overdue");
        }

        $this->info("Done. Marked {$total} task(s) overdue.");

        return self::SUCCESS;
    }
}
