<?php

use App\Http\Middleware\ApiTokenContext;
use App\Http\Middleware\ApplyTenantRls;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentTenant;
use App\Http\Middleware\SetLocale;
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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['two-factor.required' => EnsureTwoFactorEnabled::class]);

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
        $middleware->prependToPriorityList(ApplyTenantRls::class, SetCurrentTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/v1/*') || $request->expectsJson());
    })->create();
