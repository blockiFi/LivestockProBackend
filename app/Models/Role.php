<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    

    protected $fillable = ['name', 'guard_name', 'farm_id'];

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

    public static function findByName(string $name, $guardName = null, $farmId = null): self
    {
        $guardName = $guardName ?? config('auth.defaults.guard');

        $query = static::where('name', $name)
                       ->where('guard_name', $guardName);

        if (!is_null($farmId)) {
            $query->where('farm_id', $farmId);
        }

        $role = $query->first();

        if (!$role) {
            throw RoleDoesNotExist::create($name, $guardName);
        }

        return $role;
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


