<?php

use App\Http\Middleware\ApiJsonResponse;
use App\Http\Middleware\ApiTokenContext;
use App\Http\Middleware\ApplyTenantRls;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => require __DIR__.'/../routes/internal.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['two-factor.required' => EnsureTwoFactorEnabled::class]);

        // The client's address and scheme from the proxies in config/trustedproxy.php (T39-F3). Host, Port and
        // Prefix are never trusted, so a forwarded header cannot change the URLs the app generates.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->web(append: [
            SetLocale::class,
            EnsureTwoFactorEnabled::class,
            SetCurrentTenant::class,
            ApplyTenantRls::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            SetCurrentTenant::class,
            ApplyTenantRls::class,
        ]);

        // Tenant isolation runs after authentication and before route model binding, so bound
        // models are loaded with the tenant in the context and under row-level security.
        $middleware->prependToPriorityList(SubstituteBindings::class, ApplyTenantRls::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ApiTokenContext::class);
        $middleware->prependToPriorityList(Authenticate::class, ApiJsonResponse::class);
        $middleware->prependToPriorityList(ApplyTenantRls::class, SetCurrentTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/v1/*') || $request->expectsJson());
    })->create();
