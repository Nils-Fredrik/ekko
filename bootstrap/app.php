<?php

use App\Exceptions\Handler;
use App\Http\Middleware\ApiJsonResponse;
use App\Http\Middleware\ApiTokenContext;
use App\Http\Middleware\ApplyTenantRls;
use App\Http\Middleware\DenyFraming;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentTenant;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetLogContext;
use App\Logging\LogContext;
use App\Logging\SafeException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => require __DIR__.'/../routes/internal.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['two-factor.required' => EnsureTwoFactorEnabled::class]);
        $middleware->trimStrings(except: ['clickhouse_password', 'ga4_private_key', 'brand_name_confirmation']);

        // The client's address and scheme from the proxies in config/trustedproxy.php (T39-F3). Host, Port and
        // Prefix are never trusted, so a forwarded header cannot change the URLs the app generates.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        // On every response, not only the web group: error pages and the OAuth pages are not to be framed either.
        $middleware->append(DenyFraming::class);
        $middleware->prepend(SetLogContext::class);

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
        $exceptions->dontFlash([
            'key',
            'webhook_url',
            'clickhouse_password',
            'ga4_private_key',
        ]);
        $exceptions->report(function (Throwable $failure): void {
            Log::error('Application exception.', [
                ...app(LogContext::class)->forException($failure),
                'exception' => SafeException::metadata($failure),
            ]);
        })->stop();

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/v1/*') || $request->expectsJson());
    })->create();

// Keep the standard HTTP handler and callbacks, but never print raw exceptions from production CLI.
$app->singleton(ExceptionHandler::class, Handler::class);

return $app;
