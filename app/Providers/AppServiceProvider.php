<?php

namespace App\Providers;

use App\AnthropicBudget\Account;
use App\AnthropicBudget\BudgetedExtractionClient;
use App\AnthropicBudget\Estimator;
use App\Engines\EngineRegistry;
use App\Engines\GcsRawResponseStore;
use App\Engines\RawResponseStore;
use App\Extraction\AnthropicExtractionClient;
use App\Extraction\ExtractionClient;
use App\Mcp\McpTokens;
use App\Onboarding\Analysis;
use App\Onboarding\Fetch\DnsHostResolver;
use App\Onboarding\Fetch\HostResolver;
use App\Onboarding\Fetch\HttpPageTransport;
use App\Onboarding\Fetch\PageTransport;
use App\Onboarding\Model\AnthropicOnboardingClient;
use App\Onboarding\Model\OnboardingModelClient;
use App\Policies\OnboardingPolicy;
use App\Tenancy\ActingAs;
use App\Tenancy\Rls\DatabaseRoleCheck;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        $this->app->bind(ExtractionClient::class, fn (): ExtractionClient => new BudgetedExtractionClient(AnthropicExtractionClient::fromConfig(), app(Account::class), app(Estimator::class)));
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
        // A login role that could bypass row-level security stops the instance (T39-F2): /up is the web service's
        // startup probe, and the worker and the ekko:* commands check before they start. The migrations run as
        // the owner of the tables on purpose, so migrate and the other commands are not checked.
        Event::listen(DiagnosingHealth::class, function (): void {
            if (DatabaseRoleCheck::enforced()) {
                app(DatabaseRoleCheck::class)->assertSafe();
            }
        });
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = (string) $event->command;
            if (DatabaseRoleCheck::enforced() && (in_array($command, ['queue:work', 'queue:listen'], true) || str_starts_with($command, 'ekko:'))) {
                app(DatabaseRoleCheck::class)->assertSafe();
            }
        });

        // The wizard's proposals are not models, so their policy is registered by hand (ADR 0009, ADR 0018).
        Gate::policy(Analysis::class, OnboardingPolicy::class);

        // Each analysis fetches a site and calls the model, so starting them is limited per user.
        RateLimiter::for('onboarding', fn (Request $request): Limit => Limit::perMinute((int) config('ekko.wizard.analyses_per_minute'))
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('api-v1', function (Request $request) {
            $token = $request->user()?->currentAccessToken();

            return Limit::perMinute(60)->by('api-v1:'.($token instanceof PersonalAccessToken ? $token->getKey() : $request->ip()));
        });

        // The Claude connection (ADR 0016): tool calls per user, and the OAuth endpoints Claude calls without a
        // session per address.
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute((int) config('ekko.mcp.calls_per_minute'))
            ->by('mcp:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Claude's access tokens (ability "mcp") only work on /mcp, where AuthenticateMcp checks them itself. The
        // Sanctum guard (auth:sanctum on the pages, the consent page and the API) never accepts them, so a token
        // for one connection cannot reach the consent page and approve a wider connection (ADR 0016).
        Sanctum::authenticateAccessTokensUsing(fn (PersonalAccessToken $token, bool $isValid): bool => $isValid && ! in_array(McpTokens::READ, (array) $token->abilities, true));
        RateLimiter::for('mcp-oauth', fn (Request $request) => Limit::perMinute((int) config('ekko.mcp.oauth_per_minute'))
            ->by('mcp-oauth:'.$request->ip()));
        // Client registration per address per day (T39-F5), so one address cannot fill the ceiling for everyone.
        RateLimiter::for('mcp-register', fn (Request $request) => Limit::perDay((int) config('ekko.mcp.clients_per_ip_per_day'))
            ->by('mcp-register:'.$request->ip()));
    }
}
