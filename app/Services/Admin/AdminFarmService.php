<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\PoultryHouse;
use App\Models\User;
use App\Services\FarmEntitlementService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class AdminFarmService
{
    public function list(Request $request): LengthAwarePaginator
    {
        $query = Farm::withTrashed()
            ->with(['country:id,name', 'owner:id,name,email', 'subscription.plan:id,slug,name,price_kobo,ai_enabled'])
            ->withCount(['users', 'flocks', 'poultryHouses']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('owner', fn ($oq) => $oq->where('email', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $request->status);
        }

        if ($request->filled('country_id')) {
            $query->where('country_id', $request->country_id);
        }

        if ($request->filled('subscription_status')) {
            $query->whereHas('subscription', fn ($sq) => $sq->where('status', $request->subscription_status));
        }

        if ($request->filled('plan_slug')) {
            $query->whereHas('subscription.plan', fn ($pq) => $pq->where('slug', $request->plan_slug));
        }

        return $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));
    }

    public function show(Farm $farm): array
    {
        $farm->load([
            'country:id,name',
            'owner:id,name,email',
            'settings',
        ]);

        $farm->loadCount(['users', 'flocks', 'poultryHouses']);

        $users = $farm->users()->get(['users.id', 'users.name', 'users.email'])->map(function (User $user) use ($farm) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);
            $roles = $user->roles()->where('roles.farm_id', $farm->id)->pluck('roles.name');

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $roles,
            ];
        });

        $activeFlocks = Flock::where('farm_id', $farm->id)->where('status', 'active')->count();
        $totalBirds = Flock::where('farm_id', $farm->id)->where('status', 'active')->sum('quantity');

        return [
            'farm' => $farm,
            'users' => $users,
            'stats' => [
                'active_flocks' => $activeFlocks,
                'total_birds' => (int) $totalBirds,
                'houses' => PoultryHouse::where('farm_id', $farm->id)->count(),
            ],
            'subscription' => app(FarmEntitlementService::class)->summary($farm),
            'last_activity' => $this->lastActivity($farm),
        ];
    }

    public function update(Farm $farm, array $data): Farm
    {
        $farm->update($data);

        return $farm->fresh(['country', 'owner']);
    }

    public function lastActivity(Farm $farm): ?string
    {
        $dates = collect([
            Flock::where('farm_id', $farm->id)->max('updated_at'),
            $farm->updated_at,
        ])->filter();

        return $dates->max() ? Carbon::parse($dates->max())->toIso8601String() : null;
    }
}
