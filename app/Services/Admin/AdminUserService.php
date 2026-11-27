<?php

namespace App\Services\Admin;

use App\Models\Farm;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;

class AdminUserService
{
    public const SORTABLE = ['name', 'email', 'created_at', 'last_login_at', 'farms_count'];

    public function query(Request $request): Builder
    {
        $query = User::withCount('farms');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('platform_admin_only') || $request->input('type') === 'admin') {
            $query->where('is_platform_admin', true);
        } elseif ($request->input('type') === 'farm') {
            $query->where('is_platform_admin', false);
        }

        if ($request->filled('verified')) {
            $request->boolean('verified')
                ? $query->whereNotNull('email_verified_at')
                : $query->whereNull('email_verified_at');
        }

        match ($request->input('status')) {
            'active' => $query->whereNull('deactivated_at'),
            'deactivated' => $query->whereNotNull('deactivated_at'),
            'never_signed_in' => $query->whereNull('last_login_at'),
            'inactive_30d' => $query->where(fn ($q) => $q->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(30))),
            default => null,
        };

        if ($request->filled('farm_id')) {
            $query->whereHas('farms', fn ($q) => $q->where('farms.id', $request->farm_id));
        }

        if ($request->filled('joined_from')) {
            $query->whereDate('created_at', '>=', $request->joined_from);
        }
        if ($request->filled('joined_to')) {
            $query->whereDate('created_at', '<=', $request->joined_to);
        }

        $sort = in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderBy('id', $direction);
    }

    public function list(Request $request): LengthAwarePaginator
    {
        return $this->query($request)->paginate(min(100, max(5, $request->integer('per_page', 25))));
    }

    public function show(User $user): array
    {
        $user->loadCount('farms');

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $farms = $user->farms()->get(['farms.id', 'farms.name', 'farms.status', 'farms.created_by'])->map(function (Farm $farm) use ($user, $registrar) {
            $registrar->setPermissionsTeamId($farm->id);
            $user->unsetRelation('roles');
            $roles = $user->roles()->where('roles.farm_id', $farm->id)->pluck('roles.name');

            return [
                'id' => $farm->id,
                'name' => $farm->name,
                'status' => $farm->status,
                'is_owner' => (int) $farm->created_by === $user->id,
                'roles' => $roles,
            ];
        });
        $registrar->setPermissionsTeamId($previous);

        $tokens = PersonalAccessToken::where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->orderByDesc('last_used_at')
            ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at', 'expires_at']);

        return [
            'user' => $user,
            'farms' => $farms,
            'tokens' => $tokens,
            'token_count' => $tokens->count(),
        ];
    }

    public function update(User $user, array $data): User
    {
        if (array_key_exists('email_verified', $data)) {
            $user->email_verified_at = $data['email_verified'] ? ($user->email_verified_at ?? now()) : null;
            unset($data['email_verified']);
        }

        $user->fill($data)->save();

        return $user->fresh();
    }

    public function deactivate(User $user, ?string $reason): User
    {
        $user->forceFill(['deactivated_at' => now(), 'deactivation_reason' => $reason])->save();
        $this->revokeAllTokens($user);

        return $user->fresh();
    }

    public function reactivate(User $user): User
    {
        $user->forceFill(['deactivated_at' => null, 'deactivation_reason' => null])->save();

        return $user->fresh();
    }

    public function revokeAllTokens(User $user): int
    {
        return $user->tokens()->delete();
    }

    public function sendPasswordReset(User $user): string
    {
        return Password::sendResetLink(['email' => $user->email]);
    }

    public function activity(User $user): array
    {
        $farms = $user->farms()->orderByDesc('farm_users.created_at')->limit(10)->get(['farms.id', 'farms.name']);

        return [
            'recent_farms' => $farms,
            'created_farms' => Farm::where('created_by', $user->id)->count(),
            'last_login' => $user->last_login_at?->toIso8601String(),
            'last_admin_login' => $user->last_admin_login_at?->toIso8601String(),
            'member_since' => $user->created_at?->toIso8601String(),
            'admin_actions_on_user' => \App\Models\AdminAuditLog::with('adminUser:id,name,email')
                ->where('resource_type', 'user')->where('resource_id', $user->id)
                ->orderByDesc('created_at')->limit(20)->get(),
        ];
    }
}
