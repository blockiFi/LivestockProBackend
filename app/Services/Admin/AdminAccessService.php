<?php

namespace App\Services\Admin;

use App\Models\Farm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who can do what: platform admin roles (read from the real route
 * middleware, so the matrix cannot drift from the code) and the roles and
 * permissions inside each farm.
 */
class AdminAccessService
{
    private const LEVEL = ['readonly' => 1, 'analyst' => 2, 'support' => 3, 'super_admin' => 4];

    /**
     * Every admin API route with the lowest platform role that may call it.
     *
     * @return list<array{area: string, method: string, uri: string, label: string, min_role: string}>
     */
    public function platformMatrix(): array
    {
        $rows = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/admin/') || str_starts_with($uri, 'api/admin/auth')) {
                continue;
            }

            $minRole = 'readonly';
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'platform.role:')) {
                    $role = Str::after($mw, 'platform.role:');
                    if ((self::LEVEL[$role] ?? 0) > self::LEVEL[$minRole]) {
                        $minRole = $role;
                    }
                }
            }

            $path = Str::after($uri, 'api/admin/');
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $area = Str::of(Str::before($path . '/', '/'))->replace('-', ' ')->title()->value();

            $rows[] = [
                'area' => $area === '{Resource}' ? 'Data views' : $area,
                'method' => $method,
                'uri' => '/' . $path,
                'label' => $this->label($method, $path),
                'min_role' => $minRole,
            ];
        }

        usort($rows, fn ($a, $b) => [$a['area'], $a['uri'], $a['method']] <=> [$b['area'], $b['uri'], $b['method']]);

        return $rows;
    }

    private function label(string $method, string $path): string
    {
        $verb = match ($method) {
            'GET' => 'View',
            'POST' => 'Create or run',
            'PUT', 'PATCH' => 'Change',
            'DELETE' => 'Remove',
            default => $method,
        };
        $what = Str::of($path)->replaceMatches('/\{[^}]+\}/', '')->replace(['/', '-'], ' ')->squish()->value();

        return trim("{$verb} {$what}");
    }

    /**
     * Role names used across farms, how many farms have each, how many
     * people hold it, and the permission catalogue grouped for display.
     */
    public function farmRolesOverview(): array
    {
        $roles = Role::query()
            ->whereNotNull('roles.farm_id')
            ->leftJoin('model_has_roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->selectRaw('roles.name, count(distinct roles.farm_id) as farms, count(model_has_roles.model_id) as members')
            ->groupBy('roles.name')
            ->orderByDesc('members')
            ->get();

        $permissionCounts = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->whereNotNull('roles.farm_id')
            ->selectRaw('roles.name, count(*) as total, count(distinct roles.id) as role_rows')
            ->groupBy('roles.name')
            ->get()
            ->keyBy('name');

        return [
            'roles' => $roles->map(fn ($r) => [
                'name' => $r->name,
                'farms' => (int) $r->farms,
                'members' => (int) $r->members,
                'avg_permissions' => ($pc = $permissionCounts->get($r->name)) && $pc->role_rows
                    ? round($pc->total / $pc->role_rows, 1) : 0,
            ])->values(),
            'permission_groups' => $this->permissionCatalogue(),
            'total_permissions' => Permission::count(),
        ];
    }

    /** @return Collection<int, array{group: string, color: ?string, permissions: list<string>}> */
    public function permissionCatalogue(): Collection
    {
        return Permission::with('group:id,name,color')
            ->orderBy('name')
            ->get(['id', 'name', 'group_id'])
            ->groupBy(fn ($p) => $p->group?->name ?? 'Other')
            ->map(fn ($perms, $group) => [
                'group' => $group,
                'color' => $perms->first()->group?->color,
                'permissions' => $perms->pluck('name')->values()->all(),
            ])
            ->sortBy('group')
            ->values();
    }

    /** Roles on one farm with their permissions and members. */
    public function farmRoles(Farm $farm): array
    {
        $roles = Role::where('farm_id', $farm->id)->with('permissions:id,name')->orderBy('id')->get();

        $members = DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.farm_id', $farm->id)
            ->get(['model_has_roles.role_id', 'users.id', 'users.name', 'users.email'])
            ->groupBy('role_id');

        return [
            'farm' => $farm->only(['id', 'name', 'created_by']),
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->sort()->values(),
                'members' => ($members->get($role->id) ?? collect())->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email])->values(),
            ]),
            'permission_groups' => $this->permissionCatalogue(),
        ];
    }

    /**
     * @param list<string> $permissions
     */
    public function syncRolePermissions(Farm $farm, Role $role, array $permissions): Role
    {
        if ((int) $role->farm_id !== $farm->id) {
            throw ValidationException::withMessages(['role' => 'This role belongs to another farm.']);
        }

        $known = Permission::whereIn('name', $permissions)->pluck('name')->all();
        $unknown = array_values(array_diff($permissions, $known));
        if ($unknown) {
            throw ValidationException::withMessages(['permissions' => 'Unknown permissions: ' . implode(', ', $unknown)]);
        }

        if ($role->name === 'owner' && count($known) === 0) {
            throw ValidationException::withMessages(['permissions' => 'The owner role cannot be left with no permissions.']);
        }

        $this->withFarm($farm, fn () => $role->syncPermissions($known));

        return $role->fresh('permissions');
    }

    /** Replace a member's roles on one farm. Roles on their other farms are untouched. */
    public function setMemberRole(Farm $farm, User $user, Role $role): array
    {
        if ((int) $role->farm_id !== $farm->id) {
            throw ValidationException::withMessages(['role_id' => 'This role belongs to another farm.']);
        }
        if (! $farm->users()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages(['user' => 'This person is not a member of the farm.']);
        }
        if ((int) $farm->created_by === $user->id && $role->name !== 'owner') {
            throw ValidationException::withMessages(['role_id' => 'The farm creator must keep the owner role.']);
        }

        $old = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.farm_id', $farm->id)->pluck('roles.name')->all();

        // Written directly so only this farm's rows change, whatever the
        // state of the User::roles() relation scoping.
        DB::transaction(function () use ($farm, $user, $role) {
            DB::table('model_has_roles')
                ->where('model_id', $user->id)->where('model_type', User::class)
                ->where('farm_id', $farm->id)->delete();
            DB::table('model_has_roles')->insert([
                'role_id' => $role->id,
                'model_type' => User::class,
                'model_id' => $user->id,
                'farm_id' => $farm->id,
            ]);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['old' => $old, 'new' => [$role->name]];
    }

    private function withFarm(Farm $farm, callable $fn): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($farm->id);

        try {
            return $fn();
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }
    }
}
