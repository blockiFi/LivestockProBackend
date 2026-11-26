<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if (! $user->is_platform_admin || $user->deactivated_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Platform admin access required',
            ], 403);
        }

        // Only tokens issued by the admin sign-in carry this ability. A farm
        // app token ("*" abilities) for the same person is refused, so admin
        // sessions expire and always went through the admin sign-in steps.
        $token = $user->currentAccessToken();
        $ability = config('admin.token_ability', 'platform-admin');
        $abilities = is_object($token) && isset($token->abilities) ? (array) $token->abilities : [];

        if (! in_array($ability, $abilities, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Sign in to the admin console to continue',
                'code' => 'admin_session_required',
            ], 401);
        }

        return $next($request);
    }
}
