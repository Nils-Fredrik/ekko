<?php

namespace App\Http\Middleware;

use App\Mcp\McpTokens;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login on /mcp (ADR 0016): only access tokens of an active Claude connection (ability "mcp"), never ordinary
 * API tokens or a browser session. Without a valid token the answer is 401, and AddWwwAuthenticateHeader
 * (laravel/mcp) points Claude to the metadata, so the login starts. Users who must use two-factor
 * authentication (platform admin, partner advisors) are refused until it is confirmed, as on the pages.
 */
final class AuthenticateMcp
{
    /** The connection is on the request under this key. */
    public const CONNECTION = 'mcp_connection';

    public function __construct(private readonly McpTokens $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $token = $bearer !== null ? PersonalAccessToken::findToken($bearer) : null;
        $connection = $token !== null && $token->expires_at?->isFuture() === true ? $this->tokens->connectionFor($token) : null;
        $user = $token?->tokenable;

        if ($token === null || $connection === null || ! $user instanceof User || $connection->user_id !== $user->id) {
            return response()->json(['error' => 'invalid_token', 'error_description' => __('mcp.errors.reconnect')], 401);
        }
        if ($user->requiresTwoFactor() && ! $user->hasConfirmedTwoFactor()) {
            return response()->json(['error' => 'insufficient_scope', 'error_description' => __('auth.two_factor.required')], 403);
        }

        $user->withAccessToken($token);
        Auth::guard('sanctum')->setUser($user);
        Auth::shouldUse('sanctum');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set(self::CONNECTION, $connection);
        if (is_string($user->locale) && in_array($user->locale, (array) config('ekko.locales'), true)) {
            app()->setLocale($user->locale);
        }

        if ($connection->last_used_at === null || $connection->last_used_at->lt(Date::now()->subMinutes(5))) {
            $connection->forceFill(['last_used_at' => Date::now()])->saveQuietly();
        }

        return $next($request);
    }
}
