<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is required for the platform admin and partner advisors (docs/spec.md
 * 5.4, ADR 0006). Until it is confirmed they can only reach the pages that set it up.
 */
final class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->hasConfirmedTwoFactor() || ! $user->requiresTwoFactor()) {
            return $next($request);
        }

        if ($request->routeIs(...config('ekko.two_factor_exempt_routes'))) {
            return $next($request);
        }

        $message = __('auth.two_factor.required');

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return redirect()->route('profile.show')->dangerBanner($message);
    }
}
