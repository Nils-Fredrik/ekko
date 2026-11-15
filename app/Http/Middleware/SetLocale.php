<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in user's language, otherwise the choice stored in the session before login,
 * otherwise the application default (nb).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);
        if ($locale !== null) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    private function resolve(Request $request): ?string
    {
        $locales = config('ekko.locales');
        $user = $request->user();
        $userLocale = $user instanceof User ? $user->locale : null;
        $sessionLocale = $request->hasSession() ? $request->session()->get('locale') : null;

        foreach ([$userLocale, $sessionLocale] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $locales, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
