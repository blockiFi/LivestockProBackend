<?php

namespace App\Http\Middleware;

use App\Models\Farm;
use App\Services\FarmEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drops a farm to read-only once its subscription lapses. Reads still go
 * through so owners can review their data and reach the billing page.
 */
class EnsureFarmSubscription
{
    public function __construct(private readonly FarmEntitlementService $entitlements)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $farm = $this->resolveFarm($request);

        if (! $farm) {
            return $next($request);
        }

        if ($this->entitlements->canWrite($farm)) {
            return $next($request);
        }

        $denial = $this->entitlements->denialFor($farm, FarmEntitlementService::ACTION_WRITE);

        return response()->json([
            'success' => false,
            'message' => $denial['message'],
            'code' => $denial['code'],
            'upgrade_url' => config('subscription.billing_url'),
        ], $denial['status']);
    }

    private function resolveFarm(Request $request): ?Farm
    {
        $routeFarm = $request->route('farm');

        if ($routeFarm instanceof Farm) {
            return $routeFarm;
        }

        $farmId = $routeFarm ?: $request->input('farm_id');

        return $farmId ? Farm::find($farmId) : null;
    }
}
