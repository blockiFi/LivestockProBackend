<?php

namespace App\Services\Admin;

use App\Models\Farm;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;

class AdminUserService
{
    public function list(Request $request): LengthAwarePaginator
    {
        $query = User::withCount('farms');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('platform_admin_only')) {
            $query->where('is_platform_admin', true);
        }

        if ($request->filled('farm_id')) {
            $query->whereHas('farms', fn ($q) => $q->where('farms.id', $request->farm_id));
        }

        return $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));
    }

    public function show(User $user): array
    {
        $user->loadCount('farms');

        $farms = $user->farms()->get(['farms.id', 'farms.name', 'farms.status'])->map(function (Farm $farm) use ($user) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
            $roles = $user->roles()->where('roles.farm_id', $farm->id)->pluck('roles.name');

            return [
                'id' => $farm->id,
                'name' => $farm->name,
                'status' => $farm->status,
                'roles' => $roles,
            ];
        });

        $tokens = PersonalAccessToken::where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->orderByDesc('last_used_at')
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']);

        return [
            'user' => $user,
            'farms' => $farms,
            'tokens' => $tokens,
            'token_count' => $tokens->count(),
        ];
    }

    public function update(User $user, array $data): User
    {
        if (isset($data['email_verified']) && $data['email_verified']) {
            $user->email_verified_at = now();
            unset($data['email_verified']);
        }

        $user->update($data);

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
            'last_login' => $user->last_admin_login_at?->toIso8601String(),
            'member_since' => $user->created_at?->toIso8601String(),
        ];
    }
}
