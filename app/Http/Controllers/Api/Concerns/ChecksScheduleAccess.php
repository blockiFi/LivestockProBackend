<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\User;

/**
 * Farm roles from ManagesFarmRoles grant high-level schedule permissions
 * (view/create/update/delete/manage schedules). Feeding endpoints historically
 * checked finer-grained "feeding schedule*" permissions that were never synced
 * onto those roles, which made templates look empty even when they exist in DB.
 */
trait ChecksScheduleAccess
{
    protected function canViewFarmSchedules(?User $user, $farmId): bool
    {
        if (!$user || !$farmId) {
            return false;
        }

        return $user->can('view schedules', 'api', $farmId)
            || $user->can('manage schedules', 'api', $farmId)
            || $user->can('view feeding schedules', 'api', $farmId)
            || $user->can('view feeding schedule items', 'api', $farmId)
            || $user->can('view feeding batch schedules', 'api', $farmId)
            || $user->can('view feeding batch schedule items', 'api', $farmId)
            || $user->can('view batch schedules', 'api', $farmId);
    }

    protected function canCreateFarmSchedules(?User $user, $farmId): bool
    {
        if (!$user || !$farmId) {
            return false;
        }

        return $user->can('create schedules', 'api', $farmId)
            || $user->can('manage schedules', 'api', $farmId)
            || $user->can('create feeding schedules', 'api', $farmId)
            || $user->can('create feeding schedule items', 'api', $farmId)
            || $user->can('create feeding batch schedules', 'api', $farmId)
            || $user->can('create feeding batch schedule items', 'api', $farmId)
            || $user->can('create batch schedules', 'api', $farmId);
    }

    protected function canUpdateFarmSchedules(?User $user, $farmId): bool
    {
        if (!$user || !$farmId) {
            return false;
        }

        return $user->can('update schedules', 'api', $farmId)
            || $user->can('manage schedules', 'api', $farmId)
            || $user->can('update feeding schedules', 'api', $farmId)
            || $user->can('update feeding schedule items', 'api', $farmId)
            || $user->can('update feeding batch schedules', 'api', $farmId)
            || $user->can('update feeding batch schedule items', 'api', $farmId)
            || $user->can('update batch schedules', 'api', $farmId);
    }

    protected function canDeleteFarmSchedules(?User $user, $farmId): bool
    {
        if (!$user || !$farmId) {
            return false;
        }

        return $user->can('delete schedules', 'api', $farmId)
            || $user->can('manage schedules', 'api', $farmId)
            || $user->can('delete feeding schedules', 'api', $farmId)
            || $user->can('delete feeding schedule items', 'api', $farmId)
            || $user->can('delete feeding batch schedules', 'api', $farmId)
            || $user->can('delete feeding batch schedule items', 'api', $farmId)
            || $user->can('delete batch schedules', 'api', $farmId);
    }
}
