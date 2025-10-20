<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFarmRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next) : Response
{
    $farm = $request->route('farm'); // or from subdomain/session

    if (!auth()->user()->roles()->where('farm_id', $farm->id )->exists()) {
        abort(403, 'Unauthorized for this farm.');
    }

    return $next($request);
}
}
