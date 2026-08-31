<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Traits\ManagesFarmRoles;
use Illuminate\Console\Command;

class SyncFarmTaskPermissions extends Command
{
    use ManagesFarmRoles;

    protected $signature = 'farm-tasks:sync-permissions {--farm= : Specific farm id}';

    protected $description = 'Ensure farm task permissions exist on owner/manager/worker roles';

    public function handle(): int
    {
        $farmId = $this->option('farm');
        $farms = $farmId
            ? Farm::where('id', $farmId)->get()
            : Farm::query()->get();

        foreach ($farms as $farm) {
            $this->createFarmRolesAndPermissions($farm);
            $this->line("Synced task permissions for farm {$farm->id}");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
