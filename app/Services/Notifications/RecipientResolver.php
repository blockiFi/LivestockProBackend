<?php

namespace App\Services\Notifications;

use App\Models\Farm;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * Turns "who should hear about this" into concrete User models.
 */
class RecipientResolver
{
    /** @var array<int, Collection<int, User>> */
    protected array $farmUserCache = [];

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $permissions
     * @param  list<int>  $excludeUserIds
     * @return Collection<int, User>
     */
    public function resolve(?int $farmId, array $userIds, array $permissions, array $excludeUserIds = []): Collection
    {
        $recipients = collect();

        if ($userIds !== []) {
            $recipients = $recipients->merge(
                User::query()
                    ->with(['settings', 'notificationSettings'])
                    ->whereIn('id', $userIds)
                    ->get()
            );
        }

        if ($permissions !== [] && $farmId !== null) {
            $recipients = $recipients->merge($this->farmMembersWithPermission($farmId, $permissions));
        }

        return $recipients
            ->unique('id')
            ->reject(fn (User $user) => in_array((int) $user->id, array_map('intval', $excludeUserIds), true))
            ->values();
    }

    /**
     * @param  list<string>  $permissions
     * @return Collection<int, User>
     */
    public function farmMembersWithPermission(int $farmId, array $permissions): Collection
    {
        $members = $this->farmMembers($farmId);
        if ($members->isEmpty() || $permissions === []) {
            return collect();
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($farmId);

        try {
            return $members
                ->filter(function (User $user) use ($permissions) {
                    foreach ($permissions as $permission) {
                        try {
                            if ($user->can($permission)) {
                                return true;
                            }
                        } catch (\Throwable) {
                            // A permission that has not been seeded yet simply does not match.
                        }
                    }

                    return false;
                })
                ->values();
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function farmMembers(int $farmId): Collection
    {
        if (!isset($this->farmUserCache[$farmId])) {
            $farm = Farm::find($farmId);

            $this->farmUserCache[$farmId] = $farm
                ? $farm->users()->with(['settings', 'notificationSettings'])->get()
                : collect();
        }

        return $this->farmUserCache[$farmId];
    }

    public function forget(): void
    {
        $this->farmUserCache = [];
    }
}
