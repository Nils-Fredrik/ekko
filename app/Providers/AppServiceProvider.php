<?php

namespace App\Providers;

use App\Engines\EngineRegistry;
use App\Engines\GcsRawResponseStore;
use App\Engines\RawResponseStore;
use App\Extraction\AnthropicExtractionClient;
use App\Extraction\ExtractionClient;
use App\Onboarding\Analysis;
use App\Onboarding\Fetch\DnsHostResolver;
use App\Onboarding\Fetch\HostResolver;
use App\Onboarding\Fetch\HttpPageTransport;
use App\Onboarding\Fetch\PageTransport;
use App\Onboarding\Model\AnthropicOnboardingClient;
use App\Onboarding\Model\OnboardingModelClient;
use App\Policies\OnboardingPolicy;
use App\Tenancy\ActingAs;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        // The wizard (ADR 0018): the model client is resolved lazily for the same reason.
        $this->app->bind(OnboardingModelClient::class, fn (): OnboardingModelClient => AnthropicOnboardingClient::fromConfig());
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
        $this->app->bind(PageTransport::class, HttpPageTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The wizard's proposals are not models, so their policy is registered by hand (ADR 0009, ADR 0018).
        Gate::policy(Analysis::class, OnboardingPolicy::class);

        // Each analysis fetches a site and calls the model, so starting them is limited per user.
        RateLimiter::for('onboarding', fn (Request $request): Limit => Limit::perMinute((int) config('ekko.wizard.analyses_per_minute'))
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
