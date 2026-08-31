<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Services\Notifications\TaskEscalationService;
use Illuminate\Console\Command;

class EscalateOverdueFarmTasks extends Command
{
    protected $signature = 'notifications:escalate-overdue
                            {--farm= : Only escalate one farm}';

    protected $description = 'Escalate overdue farm tasks to management per farm escalation rules';

    public function handle(TaskEscalationService $escalation): int
    {
        $farmId = $this->option('farm');

        $farms = $farmId
            ? Farm::where('id', $farmId)->get(['id', 'name'])
            : Farm::query()->get(['id', 'name']);

        $total = 0;

        foreach ($farms as $farm) {
            $result = $escalation->run((int) $farm->id);
            $total += $result['escalated'];

            if ($result['escalated'] > 0) {
                $this->line("Farm {$farm->id}: escalated {$result['escalated']} of {$result['evaluated']} overdue task(s)");
            }
        }

        $this->info("Done. Sent {$total} escalation notification(s).");

        return self::SUCCESS;
    }
}
