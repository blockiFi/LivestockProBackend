<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Services\Equipment\EquipmentAlertService;
use Illuminate\Console\Command;

class DispatchEquipmentAlerts extends Command
{
    protected $signature = 'notifications:dispatch-equipment-alerts
                            {--farm= : Only dispatch alerts for one farm}';

    protected $description = 'Send maintenance and warranty reminders for farm equipment';

    public function handle(EquipmentAlertService $service): int
    {
        $farmId = $this->option('farm');

        $farms = $farmId
            ? Farm::where('id', $farmId)->get()
            : Farm::query()->get();

        $maintenanceTotal = 0;
        $warrantyTotal = 0;

        foreach ($farms as $farm) {
            $result = $service->dispatchForFarm($farm);
            $maintenanceTotal += $result['maintenance_notifications'];
            $warrantyTotal += $result['warranty_notifications'];

            if ($result['maintenance_notifications'] + $result['warranty_notifications'] > 0) {
                $this->line(sprintf(
                    'Farm %d: %d maintenance, %d warranty notification(s)',
                    $farm->id,
                    $result['maintenance_notifications'],
                    $result['warranty_notifications']
                ));
            }
        }

        $this->info("Done. Dispatched {$maintenanceTotal} maintenance and {$warrantyTotal} warranty notification(s).");

        return self::SUCCESS;
    }
}
