<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdminRole
{
    private const ROLE_HIERARCHY = [
        'readonly' => 1,
        'analyst' => 2,
        'support' => 3,
        'super_admin' => 4,
    ];

    public function handle(Request $request, Closure $next, string $minimumRole = 'readonly'): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_platform_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Platform admin access required',
            ], 403);
        }

        $userRole = $user->platform_admin_role ?? 'readonly';
        $requiredLevel = self::ROLE_HIERARCHY[$minimumRole] ?? 1;
        $userLevel = self::ROLE_HIERARCHY[$userRole] ?? 0;

        if ($userLevel < $requiredLevel) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient platform admin privileges',
            ], 403);
        }

        return $next($request);
    }
}
