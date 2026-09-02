<?php

namespace App\Services;

use App\Models\Farm;
use App\Traits\ManagesFarmRoles;

class FarmPermissionSyncService
{
    use ManagesFarmRoles;

    public function syncFarm(Farm $farm): void
    {
        $this->createFarmRolesAndPermissions($farm);
    }

    public function syncAll(): void
    {
        Farm::query()->each(fn (Farm $farm) => $this->syncFarm($farm));
    }
}
