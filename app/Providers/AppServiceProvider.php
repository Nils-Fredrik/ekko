<?php

namespace App\Providers;

use App\ActionEngine\Writing\ActionWriter;
use App\ActionEngine\Writing\AnthropicActionWriter;
use App\AnthropicBudget\Account;
use App\AnthropicBudget\BudgetedExtractionClient;
use App\AnthropicBudget\Estimator;
use App\Engines\EngineRegistry;
use App\Engines\GcsRawResponseStore;
use App\Engines\RawResponseStore;
use App\Extraction\AnthropicExtractionClient;
use App\Extraction\ExtractionClient;
use App\FactCheck\Judge\AnthropicFactJudge;
use App\FactCheck\Judge\FactJudge;
use App\Insights\InsightTextWriter;
use App\Logging\LogContext;
use App\Mcp\McpTokens;
use App\Onboarding\Analysis;
use App\Onboarding\Fetch\DnsHostResolver;
use App\Onboarding\Fetch\HostResolver;
use App\Onboarding\Fetch\HttpPageTransport;
use App\Onboarding\Fetch\PageTransport;
use App\Onboarding\Model\AnthropicOnboardingClient;
use App\Onboarding\Model\OnboardingModelClient;
use App\Policies\OnboardingPolicy;
use App\Storage\GcsReportsAdapter;
use App\Tenancy\ActingAs;
use App\Tenancy\Rls\DatabaseRoleCheck;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use App\Work\CloudTasks\CloudTasksWorkPublisher;
use App\Work\Listeners\PublishQueueWakeOnWorkerStop;
use App\Work\NullWorkPublisher;
use App\Work\QueueWorkPublisher;
use App\Work\WorkPublisher;
use App\Work\WorkSignals;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\Filesystem;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: reset between requests and between queued jobs, so a tenant never leaks into the next one.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(LogContext::class);
        $this->app->scoped(ActingAs::class);
        $this->app->scoped(TenantAccess::class);
        $this->app->scoped(EngineRegistry::class, EngineRegistry::discover(...));
        $this->app->bind(RawResponseStore::class, GcsRawResponseStore::class);
        // Resolved lazily: without ANTHROPIC_API_KEY the app boots, and only extraction fails.
        $this->app->bind(ExtractionClient::class, fn (): ExtractionClient => new BudgetedExtractionClient(AnthropicExtractionClient::fromConfig(), app(Account::class), app(Estimator::class)));
        // The wizard (ADR 0018): the model client is resolved lazily for the same reason.
        $this->app->bind(OnboardingModelClient::class, fn (): OnboardingModelClient => AnthropicOnboardingClient::fromConfig());
        $this->app->bind(ActionWriter::class, fn (): ActionWriter => AnthropicActionWriter::fromConfig());
        $this->app->bind(InsightTextWriter::class, fn (): InsightTextWriter => AnthropicActionWriter::fromConfig(settings: 'insight'));
        $this->app->bind(FactJudge::class, fn (): FactJudge => AnthropicFactJudge::fromConfig());
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
        $this->app->bind(PageTransport::class, HttpPageTransport::class);
        // Durable work (ADR 0032): scoped, so the per-instance wake-up deduplication ends with each job or request.
        $this->app->scoped(WorkSignals::class);
        $this->app->bind(WorkPublisher::class, fn (): WorkPublisher => match (config('ekko.work.publisher')) {
            'queue' => new QueueWorkPublisher,
            'cloud_tasks' => app(CloudTasksWorkPublisher::class),
            'none' => new NullWorkPublisher,
            default => throw new \InvalidArgumentException('Unknown ekko.work.publisher.'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app(LogContext::class)->clear();
        $this->configurePdf();
        $this->configureReportDisk();

        // A worker that stops while queue jobs wait for later registers its next start (T40-F4, ADR 0033).
        Event::listen(WorkerStopping::class, PublishQueueWakeOnWorkerStop::class);
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
        // Per token, and per user across all of the user's tokens, so more tokens do not give more calls (T39-F10).
        RateLimiter::for('api-v1', function (Request $request) {
            $user = $request->user();
            $token = $user?->currentAccessToken();
            $limits = [Limit::perMinute((int) config('ekko.api.calls_per_minute_per_token'))
                ->by('api-v1:'.($token instanceof PersonalAccessToken ? $token->getKey() : $request->ip()))];
            if ($user !== null) {
                $limits[] = Limit::perMinute((int) config('ekko.api.calls_per_minute_per_user'))->by('api-v1-user:'.$user->getAuthIdentifier());
            }

            return $limits;
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

    /**
     * PDF reports use Chromium through spatie/laravel-pdf (docs/spec.md 11.2). The jobs image has the
     * binary at CHROME_PATH and no Node, so the Chrome PHP driver is used instead of Browsershot.
     */
    private function configurePdf(): void
    {
        $binary = config('ekko.reports.chrome_binary');
        if (! is_string($binary) || ! is_file($binary)) {
            foreach (['/usr/bin/chromium', '/usr/bin/chromium-browser', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'] as $candidate) {
                if (is_file($candidate)) {
                    $binary = $candidate;
                    break;
                }
            }
        }

        config([
            'laravel-pdf.driver' => 'chrome',
            'laravel-pdf.chrome.chrome_binary' => is_string($binary) ? $binary : null,
            'laravel-pdf.chrome.no_sandbox' => true,
            'laravel-pdf.chrome.operation_timeout' => 60000,
            'laravel-pdf.chrome.timeout' => 60000,
        ]);
    }

    /** Logos and PDFs share the reports bucket when EKKO_REPORTS_BUCKET is set (docs/spec.md 11.2). */
    private function configureReportDisk(): void
    {
        Storage::extend('gcs', function (mixed $app, array $config): FilesystemAdapter {
            $project = $config['project_id'] ?? null;
            $client = new StorageClient([
                'projectId' => is_string($project) && $project !== '' ? $project : null,
            ]);
            $adapter = new GcsReportsAdapter($client, (string) ($config['bucket'] ?? ''));

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
    }
}
