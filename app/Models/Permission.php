<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;


class Permission extends SpatiePermission
{
    

    protected $fillable = ['name', 'guard_name'];

    // public static function findOrCreate(string $name, $guardName = null, $farmId = null): self
    // {
    //     $guardName = $guardName ?? config('auth.defaults.guard');

    //     $query = static::where('name', $name)
    //         ->where('guard_name', $guardName);

    //     if (!is_null($farmId)) {
    //         $query->where('farm_id', $farmId);
    //     }

    //     $permission = $query->first();

    //     if ($permission) {
    //         return $permission;
    //     }

    //     return static::create([
    //         'name' => $name,
    //         'guard_name' => $guardName,
    //         'farm_id' => $farmId,
    //     ]);
    // }

    // Optional: override findByName to consider farm_id
    public static function findByName(string $name, $guardName = null, $farmId = null): self
    {
        $guardName = $guardName ?? config('auth.defaults.guard');

        $query = static::where('name', $name)
                       ->where('guard_name', $guardName);

        if (!is_null($farmId)) {
            $query->where('farm_id', $farmId);
        }

        $permission = $query->first();

        if (!$permission) {
            throw new \App\Exceptions\PermissionDoesNotExist($name, $guardName);
        }

        return $permission;
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('farm_id');
    }

    public function scopeForFarm($query, $farmId)
    {
        return $query->where('farm_id', $farmId);
    }

}
