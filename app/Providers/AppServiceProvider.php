<?php

namespace App\Providers;

use App\Engines\EngineRegistry;
use App\Engines\GcsRawResponseStore;
use App\Engines\RawResponseStore;
use App\Extraction\AnthropicExtractionClient;
use App\Extraction\ExtractionClient;
use App\Tenancy\ActingAs;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: reset between requests and between queued jobs, so a tenant never leaks into the next one.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(ActingAs::class);
        $this->app->scoped(TenantAccess::class);
        $this->app->scoped(EngineRegistry::class, EngineRegistry::discover(...));
        $this->app->bind(RawResponseStore::class, GcsRawResponseStore::class);
        // Resolved lazily: without ANTHROPIC_API_KEY the app boots, and only extraction fails.
        $this->app->bind(ExtractionClient::class, fn (): ExtractionClient => AnthropicExtractionClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The Claude connection (ADR 0016): tool calls per user, and the OAuth endpoints Claude calls without a
        // session per address.
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute((int) config('ekko.mcp.calls_per_minute'))
            ->by('mcp:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('mcp-oauth', fn (Request $request) => Limit::perMinute((int) config('ekko.mcp.oauth_per_minute'))
            ->by('mcp-oauth:'.$request->ip()));
    }
}
