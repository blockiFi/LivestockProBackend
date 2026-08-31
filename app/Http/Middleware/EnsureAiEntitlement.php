<?php

namespace App\Http\Middleware;

use App\Models\Farm;
use App\Services\FarmEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard gate for every AI-backed endpoint. Farms without an AI-enabled plan get
 * a clear 403 rather than a silent "ai_available: false" response.
 */
class EnsureAiEntitlement
{
    public function __construct(private readonly FarmEntitlementService $entitlements)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $farm = $this->resolveFarm($request);

        if (! $farm) {
            return $next($request);
        }

        $denial = $this->entitlements->denialFor($farm, FarmEntitlementService::ACTION_USE_AI);

        if ($denial === null) {
            return $next($request);
        }

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
