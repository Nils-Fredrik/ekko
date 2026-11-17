<?php

namespace App\Http\Middleware;

use App\Api\TokenTenant;
use App\Logging\LogContext;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** A v1 token reaches exactly one tenant, including at the database layer. */
final class ApiTokenContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $access = app(TenantAccess::class);
        $context->forget();
        $access->flush();
        $user = $request->user();
        app(LogContext::class)->share(['user_id' => $user instanceof User ? $user->id : null]);
        $token = $user instanceof User ? $user->currentAccessToken() : null;
        abort_unless($token instanceof PersonalAccessToken, 403);
        $id = TokenTenant::id($token);
        $tenant = $id === null ? null : Team::query()->find($id);
        abort_unless($tenant !== null && $access->canSelect($user, $tenant), 403);
        app()->setLocale(in_array($user->locale, ['nb', 'sv', 'en'], true) ? $user->locale : $tenant->default_locale);

        return $context->run($tenant->id, fn () => app(TenantRls::class)->run(
            [$tenant->id],
            fn () => $next($request),
            fn (Response $response): bool => $response->getStatusCode() < 400,
        ));
    }
}
