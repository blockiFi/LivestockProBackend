<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Services\Notifications\FarmOperationsNotifier;
use Illuminate\Console\Command;

class DispatchFarmOperationAlerts extends Command
{
    protected $signature = 'notifications:dispatch-farm-alerts
                            {--farm= : Only dispatch alerts for one farm}';

    protected $description = 'Convert farm operation alerts (stock, health, feeding, medication) into notifications';

    public function handle(FarmOperationsNotifier $notifier): int
    {
        $farmId = $this->option('farm');

        $farms = $farmId
            ? Farm::where('id', $farmId)->get()
            : Farm::query()->get();

        $total = 0;

        foreach ($farms as $farm) {
            $sent = $notifier->dispatchForFarm($farm);
            $total += $sent;

            if ($sent > 0) {
                $this->line("Farm {$farm->id}: dispatched {$sent} notification(s)");
            }
        }

        $this->info("Done. Dispatched {$total} farm-operation notification(s).");

        return self::SUCCESS;
    }
}
