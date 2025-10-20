<?php

namespace App\Traits;

use App\Models\Permission;
use Illuminate\Support\Facades\Auth;

trait HasFarmPermissions
{
    public function hasFarmPermission(string $permission, ?int $farmId = null): bool
    {
        $user = Auth::user();

        if (!$user) return false;
        if (!$user->farms()->where('farm_id', $farmId)->exists()) {
            return false;
        }
        return $user->permissions()
            ->where('name', $permission)
            ->where(function ($q) use ($farmId) {
                $q->whereNull('farm_id');
                if ($farmId) {
                    $q->orWhere('farm_id', $farmId);
                }
            })
            ->exists()
            ||
            $user->roles()
            ->where(function ($q) use ($farmId) {
                $q->whereNull('farm_id');
                if ($farmId) {
                    $q->orWhere('farm_id', $farmId);
                }
            })
            ->whereHas('permissions', fn($q) => $q->where('name', $permission))
            ->exists();
    }
    // public function hasFarmPermission(string $permission, ?int $farmId = null): bool
    // {
    //     $user = Auth::user();

    //     if (!$user) return false;
    
    //     // Direct permissions with exact farm match
    //     $hasDirectPermission = $user->permissions()
    //         ->where('name', $permission)
    //         ->where('farm_id', $farmId)
    //         ->exists();
    
    //     // Role permissions with exact farm match
    //     $hasRolePermission = $user->roles()
    //         ->where('farm_id', $farmId)
    //         ->whereHas('permissions', function ($query) use ($permission) {
    //             $query->where('name', $permission);
    //         })
    //         ->exists();
    
    //     return $hasDirectPermission || $hasRolePermission;
    
    // }
}