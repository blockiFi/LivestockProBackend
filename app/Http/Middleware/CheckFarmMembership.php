<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Farm;
use Spatie\Permission\PermissionRegistrar;
class CheckFarmMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Support both numeric ID and implicit route-model binding for {farm}
        $routeFarm = $request->route('farm');

        if ($routeFarm instanceof Farm) {
            $farm = $routeFarm;
            $farmId = $farm->id;
        } else {
            $farmId = $routeFarm;
            if (!$farmId && $request->has('farm_id')) {
                $farmId = $request->farm_id;
            }

            if (!$farmId) {
                return response()->json([
                    'message' => 'Farm ID is required'
                ], 400);
            }

            $farm = Farm::find($farmId);
        }

        if (!$farm) {
            return response()->json([
                'message' => 'Farm not found'
            ], 404);
        }

        if (! $farm->status) {
            return response()->json([
                'message' => 'This farm has been suspended. Please contact support.'
            ], 403);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        if (!$user->farms()->where('farms.id', $farmId)->exists()) {
            return response()->json([
                'message' => 'You do not have access to this farm'
            ], 403);
        }

        return $next($request);
    }
} 