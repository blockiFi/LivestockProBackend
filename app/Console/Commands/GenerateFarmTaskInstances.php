<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Services\TaskInstanceGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateFarmTaskInstances extends Command
{
    protected $signature = 'farm-tasks:generate {--days=30 : Days ahead to generate} {--farm= : Specific farm id}';

    protected $description = 'Generate upcoming farm task instances from active schedules';

    public function handle(TaskInstanceGenerator $generator): int
    {
        $days = (int) $this->option('days');
        $from = Carbon::today();
        $to = Carbon::today()->addDays($days);
        $farmId = $this->option('farm');

        $farms = $farmId
            ? Farm::where('id', $farmId)->get()
            : Farm::query()->get(['id', 'name']);

        $total = 0;
        foreach ($farms as $farm) {
            $n = $generator->generateForFarm((int) $farm->id, $from, $to);
            $total += $n;
            $this->line("Farm {$farm->id}: generated {$n} instances");
        }

        $this->info("Done. Created {$total} new task instance(s).");

        return self::SUCCESS;
    }
}
