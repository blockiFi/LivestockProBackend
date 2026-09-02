<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Services\FarmPermissionSyncService;
use Illuminate\Console\Command;

class SyncFarmTaskPermissions extends Command
{
    protected $signature = 'farm-tasks:sync-permissions {--farm= : Specific farm id}';

    protected $description = 'Ensure default farm role permissions are synced (tasks, CRM, invoices, etc.)';

    public function handle(FarmPermissionSyncService $syncService): int
    {
        $farmId = $this->option('farm');

        if ($farmId) {
            $farm = Farm::findOrFail($farmId);
            $syncService->syncFarm($farm);
            $this->line("Synced permissions for farm {$farm->id}");
        } else {
            Farm::query()->each(function (Farm $farm) use ($syncService) {
                $syncService->syncFarm($farm);
                $this->line("Synced permissions for farm {$farm->id}");
            });
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
