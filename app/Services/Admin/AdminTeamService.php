<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Who can use the admin console and with which platform role. Only super
 * admins call this (enforced on the routes), and these rules always hold:
 * nobody changes their own access, and the last super admin stays.
 */
class AdminTeamService
{
    public const ROLES = ['super_admin', 'support', 'analyst', 'readonly'];

    public function list(): Collection
    {
        return User::where('is_platform_admin', true)
            ->orderByRaw("CASE platform_admin_role WHEN 'super_admin' THEN 1 WHEN 'support' THEN 2 WHEN 'analyst' THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'platform_admin_role', 'last_admin_login_at', 'deactivated_at', 'email_verified_at', 'created_at']);
    }

    /**
     * Give admin access to an existing user, or create the account and email
     * a link to set a password.
     *
     * @return array{user: User, created: bool}
     */
    public function invite(User $actor, string $email, ?string $name, string $role): array
    {
        $this->assertRole($role);
        $user = User::where('email', $email)->first();
        $created = false;

        if ($user) {
            if ($user->id === $actor->id) {
                throw ValidationException::withMessages(['email' => 'You cannot change your own admin access.']);
            }
            if ($user->is_platform_admin) {
                throw ValidationException::withMessages(['email' => 'This person is already on the admin team.']);
            }
        } else {
            $user = User::create([
                'name' => $name ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Str::password(32),
            ]);
            $created = true;
        }

        $user->forceFill(['is_platform_admin' => true, 'platform_admin_role' => $role])->save();

        if ($created) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return ['user' => $user->fresh(), 'created' => $created];
    }

    public function changeRole(User $actor, User $user, string $role): User
    {
        $this->assertRole($role);
        $this->assertNotSelf($actor, $user);

        if (! $user->is_platform_admin) {
            throw ValidationException::withMessages(['user' => 'This person is not on the admin team.']);
        }

        if ($user->platform_admin_role === 'super_admin' && $role !== 'super_admin') {
            $this->assertNotLastSuperAdmin($user);
        }

        $user->forceFill(['platform_admin_role' => $role])->save();
        $this->endAdminSessions($user);

        return $user->fresh();
    }

    public function remove(User $actor, User $user): User
    {
        $this->assertNotSelf($actor, $user);

        if ($user->platform_admin_role === 'super_admin') {
            $this->assertNotLastSuperAdmin($user);
        }

        $user->forceFill(['is_platform_admin' => false, 'platform_admin_role' => null])->save();
        $this->endAdminSessions($user);

        return $user->fresh();
    }

    /** Admin tokens carry the old role's trust; make the person sign in again. */
    public function endAdminSessions(User $user): int
    {
        return $user->tokens()->where('abilities', 'like', '%' . config('admin.token_ability') . '%')->delete();
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Unknown admin role.']);
        }
    }

    private function assertNotSelf(User $actor, User $user): void
    {
        if ($actor->id === $user->id) {
            throw ValidationException::withMessages(['user' => 'You cannot change your own admin access.']);
        }
    }

    private function assertNotLastSuperAdmin(User $user): void
    {
        $others = User::where('is_platform_admin', true)
            ->where('platform_admin_role', 'super_admin')
            ->whereNull('deactivated_at')
            ->where('id', '!=', $user->id)
            ->count();

        if ($others === 0) {
            throw ValidationException::withMessages(['user' => 'There must always be at least one super admin.']);
        }
    }
}
