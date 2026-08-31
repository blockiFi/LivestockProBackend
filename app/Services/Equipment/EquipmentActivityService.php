<?php

namespace App\Services\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentActivityLog;
use App\Models\User;

class EquipmentActivityService
{
    public function log(
        Equipment $equipment,
        string $action,
        string $summary,
        ?User $actor = null,
        array $meta = [],
    ): EquipmentActivityLog {
        return EquipmentActivityLog::create([
            'farm_id' => $equipment->farm_id,
            'equipment_id' => $equipment->id,
            'action' => $action,
            'summary' => $summary,
            'meta' => $meta ?: null,
            'actor_id' => $actor?->id,
        ]);
    }
}
