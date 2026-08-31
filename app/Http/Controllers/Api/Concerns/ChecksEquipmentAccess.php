<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Farm;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\PermissionRegistrar;

trait ChecksEquipmentAccess
{
    protected function farmContext(int|string $farmId): Farm
    {
        $farm = Farm::findOrFail($farmId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        return $farm;
    }

    protected function canViewEquipment(Farm $farm): bool
    {
        $user = auth()->user();

        return $user->can('view equipment', 'api', $farm->id)
            || $user->can('manage equipment', 'api', $farm->id);
    }

    protected function canManageEquipment(Farm $farm): bool
    {
        return auth()->user()->can('manage equipment', 'api', $farm->id);
    }

    protected function canViewFinancials(Farm $farm): bool
    {
        $user = auth()->user();

        return $user->can('view equipment financials', 'api', $farm->id)
            || $user->can('manage equipment', 'api', $farm->id);
    }

    protected function denyView(): JsonResponse
    {
        return $this->sendError('You do not have permission to view equipment', [], 403);
    }

    protected function denyManage(): JsonResponse
    {
        return $this->sendError('You do not have permission to manage equipment', [], 403);
    }

    protected function applyWorkerScope($query, Farm $farm): void
    {
        if ($this->canManageEquipment($farm)) {
            return;
        }

        $query->where('assigned_to_user_id', auth()->id());
    }
}
