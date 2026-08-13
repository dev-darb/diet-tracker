<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends authenticated users who have not finished onboarding to the onboarding
 * flow before they can reach the main app (brief §6.3/§6.4).
 */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasCompletedOnboarding()) {
            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
