<?php

namespace App\Traits;

use App\Models\Farm;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasFarmRoles
{
    /**
     * Get all roles for a specific farm or global.
     */
    public function getFarmRoles(?int $farmId = null): Collection
    {
        return $this->roles()
            ->when($farmId, fn($q) => $q->where('farm_id', $farmId))
            ->when(!$farmId, fn($q) => $q->whereNull('farm_id'))
            ->get();
    }


    /**
     * Get all permissions for a specific farm.
     */
    public function getFarmPermissions(Farm $farm): BelongsToMany
    {
        return $this->permissions()->where('farm_id', $farm->id);
    }

    /**
     * Check if the user has a specific role for a farm or global.
     */
    public function hasFarmRole(string $roleName, ?int $farmId = null): bool
    {
        return $this->roles()
            ->where('name', $roleName)
            ->where(function ($query) use ($farmId) {
                if ($farmId) {
                    $query->where('farm_id', $farmId);
                } else {
                    $query->whereNull('farm_id');
                }
            })
            ->exists();
    }


    /**
     * Check if the user has any of the given roles for a farm.
     */
    public function hasAnyFarmRole(array $roles, Farm $farm): bool
    {
        return $this->roles()
            ->whereIn('name', $roles)
            ->where('farm_id', $farm->id)
            ->exists();
    }

    /**
     * Check if the user has all of the given roles for a farm.
     */
    public function hasAllFarmRoles(array $roles, Farm $farm): bool
    {
        return $this->roles()
            ->whereIn('name', $roles)
            ->where('farm_id', $farm->id)
            ->count() === count($roles);
    }

    /**
     * Check if the user has a specific permission for a farm.
     */
    public function hasFarmPermission(string $permission, Farm $farm): bool
    {
        return $this->permissions()
            ->where('name', $permission)
            ->where('farm_id', $farm->id)
            ->exists();
    }

    /**
     * Check if the user has any of the given permissions for a farm.
     */
    public function hasAnyFarmPermission(array $permissions, Farm $farm): bool
    {
        return $this->permissions()
            ->whereIn('name', $permissions)
            ->where('farm_id', $farm->id)
            ->exists();
    }

    /**
     * Check if the user has all of the given permissions for a farm.
     */
    public function hasAllFarmPermissions(array $permissions, Farm $farm): bool
    {
        return $this->permissions()
            ->whereIn('name', $permissions)
            ->where('farm_id', $farm->id)
            ->count() === count($permissions);
    }

    /**
     * Assign a role to the user for a specific farm or global.
     */
    public function assignFarmRole(string $roleName, ?int $farmId = null)
    {
        $role = Role::where('name', $roleName)
            ->where('farm_id', $farmId)
            ->firstOrFail();

        return $this->roles()->syncWithoutDetaching($role->id);
    }


    /**
     * Remove a role from the user for a specific farm.
     */
    public function removeFarmRole(string $role, Farm $farm): void
    {
        $roleModel = Role::where('name', $role)
            ->where('farm_id', $farm->id)
            ->firstOrFail();

        $this->roles()->detach($roleModel->id);
    }

    /**
     * Give a permission to the user for a specific farm.
     */
    public function giveFarmPermission(string $permission, Farm $farm): void
    {
        $permissionModel = Permission::where('name', $permission)
            ->where('farm_id', $farm->id)
            ->firstOrFail();

        $this->permissions()->attach($permissionModel->id, ['farm_id' => $farm->id]);
    }

    /**
     * Revoke a permission from the user for a specific farm.
     */
    public function revokeFarmPermission(string $permission, Farm $farm): void
    {
        $permissionModel = Permission::where('name', $permission)
            ->where('farm_id', $farm->id)
            ->firstOrFail();

        $this->permissions()->detach($permissionModel->id);
    }
} 