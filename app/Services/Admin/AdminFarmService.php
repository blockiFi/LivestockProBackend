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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class AdminFarmService
{
    public const SORTABLE = ['name', 'created_at', 'users_count', 'flocks_count', 'poultry_houses_count'];

    public function query(Request $request): Builder
    {
        $query = Farm::withTrashed()
            ->with(['country:id,name', 'owner:id,name,email', 'subscription.plan:id,slug,name,price_kobo,ai_enabled'])
            ->withCount(['users', 'flocks', 'poultryHouses']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('owner', fn ($oq) => $oq->where('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            match ((string) $request->status) {
                'deleted' => $query->onlyTrashed(),
                'active', '1', 'true' => $query->whereNull('deleted_at')->where('status', true),
                'suspended', 'inactive', '0', 'false' => $query->whereNull('deleted_at')->where('status', false),
                default => null,
            };
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

        if ($request->filled('created_from')) {
            $query->whereDate('created_at', '>=', $request->created_from);
        }
        if ($request->filled('created_to')) {
            $query->whereDate('created_at', '<=', $request->created_to);
        }

        $sort = in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderBy('id', $direction);
    }

    public function list(Request $request): LengthAwarePaginator
    {
        return $this->query($request)->paginate(min(100, max(5, $request->integer('per_page', 25))));
    }

    public function show(Farm $farm): array
    {
        $farm->load([
            'country:id,name',
            'owner:id,name,email',
            'settings',
        ]);

        $farm->loadCount(['users', 'flocks', 'poultryHouses']);

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($farm->id);

        $users = $farm->users()->withPivot('created_at')->get(['users.id', 'users.name', 'users.email', 'users.last_login_at', 'users.deactivated_at'])->map(function (User $user) use ($farm) {
            $user->unsetRelation('roles');
            $roles = $user->roles()->where('roles.farm_id', $farm->id)->pluck('roles.name');

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $roles,
                'is_owner' => (int) $farm->created_by === $user->id,
                'joined_at' => $user->pivot?->created_at ? \Carbon\Carbon::parse($user->pivot->created_at)->toIso8601String() : null,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'deactivated' => $user->deactivated_at !== null,
            ];
        });
        $registrar->setPermissionsTeamId($previous);

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
