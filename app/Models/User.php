<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Traits\HasRoles;
use App\Traits\HasFarmRoles;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Spatie\Permission\PermissionRegistrar;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable,  HasRoles;
    protected $guard_name = 'api';
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'profile_photo'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function farms(): BelongsToMany
    {
        return $this->belongsToMany(Farm::class, 'farm_users');
    }
    public function accessibleFarms()
    {
        return Farm::whereHas('roles', function ($query) {
            $query->whereHas('users', function ($q) {
                $q->where('users.id', $this->id);
            });
        });
    }
    /**
     * Get the permissions for a specific farm.
     */
    public function getPermissionsForFarm($farmId)
    {
        // Get all permissions for the user for a specific farm (team)
        // This assumes Spatie\Permission is set up for teams (farms)
        $farmId = is_object($farmId) ? $farmId->id : $farmId;

        // Set the team context for Spatie\Permission
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($farmId);

        // Get all permissions via roles and direct permissions for this farm
        $permissions = $this->getAllPermissions()
            ->where('pivot.farm_id', $farmId)
            ->pluck('name')
            ->unique()
            ->values();

        return $permissions;
    }

    /**
     * Check if the user has a specific permission for a farm.
     */
    public function hasPermissionForFarm($permission, $farmId)
    {
        return $this->hasPermissionTo($permission, $farmId);
    }

    /**
     * Check if the user has any of the given permissions for a farm.
     */
    public function hasAnyPermissionForFarm($permissions, $farmId)
    {
        return $this->hasAnyPermission($permissions, $farmId);
    }
     /**
     * Check if the user has all of the given permissions for a farm.
     */
    public function hasAllPermissionsForFarm($permissions, $farmId)
    {
        return $this->hasAllPermissions($permissions, $farmId);
    }

     /**
     * Assign a role to the user for a specific farm.
     */
    public function assignRoleForFarm($role, $farmId)
    {
        if (is_string($role)) {
            $role = Role::where('name', $role)
                ->where('farm_id', $farmId)
                ->firstOrFail();
        }

        return $this->assignRole($role);
    }

    /**
     * Remove a role from the user for a specific farm.
     */
    public function removeRoleForFarm($role, $farmId)
    {
        if (is_string($role)) {
            $role = Role::where('name', $role)
                ->where('farm_id', $farmId)
                ->firstOrFail();
        }

        return $this->removeRole($role);
    }
   
    public function givePermissionToForFarm($permissions, $farmId)
    {
        $permissions = collect($permissions)->map(function ($permission) use ($farmId) {
            if (is_string($permission)) {
                return Permission::where('name', $permission)
                    ->where('farm_id', $farmId)
                    ->firstOrFail();
            }
            return $permission;
        });

        return $this->givePermissionTo($permissions);
    }


    public function getRolesForFarm(Farm $farm): BelongsToMany
    {
        return $this->roles()->where('farm_id', $farm->id);
    }
    public function revokePermissionToForFarm($permissions, $farmId)
    {
        $permissions = collect($permissions)->map(function ($permission) use ($farmId) {
            if (is_string($permission)) {
                return Permission::where('name', $permission)
                    ->where('farm_id', $farmId)
                    ->firstOrFail();
            }
            return $permission;
        });

        return $this->revokePermissionTo($permissions);
    }

    public function roles(): BelongsToMany
    {
        return $this->morphToMany(
            Role::class,
            'model',
            'model_has_roles',
            'model_id',
            'role_id'
        )->where('model_type', User::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->morphToMany(
            Permission::class,
            'model',
            'model_has_permissions',
            'model_id',
            'permission_id'
        )->where('model_type', User::class);
    }

    public function createdFarms(): HasMany
    {
        return $this->hasMany(Farm::class, 'created_by');
    }

    public function createdPoultryTypes(): HasMany
    {
        return $this->hasMany(PoultryType::class, 'created_by');
    }

    public function createdPoultryHouses(): HasMany
    {
        return $this->hasMany(PoultryHouse::class, 'created_by');
    }

    public function createdPoultryFeedTypes(): HasMany
    {
        return $this->hasMany(PoultryFeedType::class, 'created_by');
    }

    public function createdPoultryFeedInventories(): HasMany
    {
        return $this->hasMany(PoultryFeedInventory::class, 'created_by');
    }

    public function createdPoultryMedicationInventories(): HasMany
    {
        return $this->hasMany(PoultryMedicationInventory::class, 'created_by');
    }

    public function createdPoultryVaccineInventories(): HasMany
    {
        return $this->hasMany(PoultryVaccineInventory::class, 'created_by');
    }

    public function createdSchedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'created_by');
    }

    public function createdScheduleItems(): HasMany
    {
        return $this->hasMany(ScheduleItem::class, 'created_by');
    }

    public function createdSalesRecords(): HasMany
    {
        return $this->hasMany(SalesRecord::class, 'created_by');
    }
}
