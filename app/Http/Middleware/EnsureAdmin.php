<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the internal admin console (BUILD_PLAN §11). Any authenticated user who
 * is not flagged `is_admin` receives a 403; guests are handled upstream by the
 * `auth` middleware. There is no self-promotion path — see `app:make-admin`.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403);
        }

        return $next($request);
    }
}
