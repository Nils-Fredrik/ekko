<?php

use App\Http\Controllers\Admin\ActingAsController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\EngineController as AdminEngineController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\TenantController as AdminTenantController;
use App\Http\Controllers\Admin\UsageController as AdminUsageController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\BotLogUploadController;
use App\Http\Controllers\BrandActionController;
use App\Http\Controllers\BrandCompetitorController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\BrandCrawlerController;
use App\Http\Controllers\BrandFactController;
use App\Http\Controllers\BrandIdentityController;
use App\Http\Controllers\BrandMeasurementPlanController;
use App\Http\Controllers\BrandPromptController;
use App\Http\Controllers\BrandResponseController;
use App\Http\Controllers\BrandSettingsController;
use App\Http\Controllers\BrandSourceController;
use App\Http\Controllers\BrandTopicController;
use App\Http\Controllers\CommandPaletteController;
use App\Http\Controllers\CompetitorSetupController;
use App\Http\Controllers\EntityDecisionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MarketSetupController;
use App\Http\Controllers\NotificationSetupController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PartnerOverviewController;
use App\Http\Controllers\PitchController;
use App\Http\Controllers\PitchReportController;
use App\Http\Controllers\PromptLibraryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportLinkController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\SetupCompetitorController;
use App\Http\Controllers\SetupIdentityController;
use App\Http\Controllers\SetupIntegrationController;
use App\Http\Controllers\SetupMarketController;
use App\Http\Controllers\SetupPromptController;
use App\Http\Controllers\SharedReportController;
use App\Http\Controllers\TeamSettingsController;
use App\Http\Controllers\UsageController;
use App\Http\Middleware\AuthenticateSessionAcrossGuards;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\SharedReportThrottle;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\ProfileInformationController;
use Laravel\Jetstream\Http\Controllers\Inertia\CurrentUserController;
use Laravel\Jetstream\Http\Controllers\Inertia\OtherBrowserSessionsController;

// No public landing page: guests are sent to the login page by the auth middleware.
Route::redirect('/', '/dashboard')->name('home');

// Language, including on the login page before anyone is signed in (docs/spec.md 14.1).
Route::put('/locale', [LocaleController::class, 'update'])->name('locale.update');

Route::get('/shared-report/{tenant}/{selector}', SharedReportController::class)->middleware(SharedReportThrottle::class.':60,1')->withoutMiddleware(EnsureTwoFactorEnabled::class)->name('reports.shared');

// Login with Google (docs/spec.md 5.4), rate limited per IP. The callback is the one URL registered with the
// provider, so it also finishes a confirmation for a signed-in user (T07-F2); a guest logs in there. A signed-in
// session that was logged out elsewhere is logged out here too before it can be confirmed (T39-F4).
Route::middleware(['throttle:sso', config('jetstream.auth_session')])->prefix('auth/{provider}')->whereIn('provider', config('ekko.sso_providers'))->group(function () {
    Route::get('redirect', [SocialLoginController::class, 'redirect'])->middleware('guest')->name('social.redirect');
    Route::get('callback', [SocialLoginController::class, 'callback'])->name('social.callback');
    // A signed-in user with a linked account confirms with the provider instead of a password (T07-F2).
    Route::get('confirm', [SocialLoginController::class, 'confirm'])->middleware('auth:'.config('fortify.guard'))->name('social.confirm');
});

// Fortify routes that check the password or send a mail, defined again with a rate limit (T39-F4). The app
// routes load after Fortify's, so these replace the routes with the same method and path. Same
// controllers, names and middleware as Fortify, plus throttle.
Route::middleware(AuthenticateSessionAcrossGuards::class)->group(function () {
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware(['guest:'.config('fortify.guard'), 'throttle:forgot-password'])
        ->name('password.email');

    Route::middleware(['auth:'.config('fortify.guard'), 'throttle:password-check'])->group(function () {
        Route::post('/user/confirm-password', [ConfirmablePasswordController::class, 'store'])->name('password.confirm.store');
        Route::put('/user/profile-information', [ProfileInformationController::class, 'update'])->name('user-profile-information.update');
        Route::put('/user/password', [PasswordController::class, 'update'])->name('user-password.update');
    });
});

// Jetstream routes that check the password, with the same limit: without it they would be a way to guess
// the password without a limit (T39-F4). Same controllers, names and middleware as Jetstream.
Route::middleware(['auth:'.config('jetstream.guard'), config('jetstream.auth_session'), 'throttle:password-check'])->group(function () {
    Route::delete('/user/other-browser-sessions', [OtherBrowserSessionsController::class, 'destroy'])->name('other-browser-sessions.destroy');
    Route::delete('/user', [CurrentUserController::class, 'destroy'])->name('current-user.destroy');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/admin/billing', [BillingController::class, 'index'])->name('admin.billing.index');
    Route::get('/admin/billing/export', [BillingController::class, 'export'])->name('admin.billing.export');
    Route::put('/admin/billing/{team}/markup', [BillingController::class, 'update'])->name('admin.billing.markup');

    Route::get('/dashboard', HomeController::class)->name('dashboard');
    Route::get('/palette', CommandPaletteController::class)->name('palette.search');
    Route::get('/partner', PartnerOverviewController::class)->name('partner.index');
    Route::get('/pitches', [PitchController::class, 'index'])->name('pitches.index');
    Route::post('/pitches/{team}/open', [PitchController::class, 'open'])->name('pitches.open');
    Route::post('/partner/pitches', [PitchController::class, 'store'])->name('partner.pitches.store');
    Route::post('/admin/pitches', [PitchController::class, 'adminStore'])->name('admin.pitches.store');
    Route::post('/pitches/{team}/convert', [PitchController::class, 'convert'])->name('pitches.convert');
    Route::get('/brands/{brand}/pitch-report', PitchReportController::class)->name('pitches.report');
    Route::get('/brands/{brand}/pitch-report/pdf', [PitchReportController::class, 'download'])->name('pitches.report.pdf');
    Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('/brands/{brand}/settings', [BrandSettingsController::class, 'show'])->name('brands.settings');
    Route::post('/brands/{brand}/markets', [MarketSetupController::class, 'store'])->name('brands.markets.store');
    Route::patch('/brands/{brand}/markets/{market}', [MarketSetupController::class, 'update'])->name('brands.markets.update');
    Route::patch('/brands/{brand}/identity', [BrandIdentityController::class, 'update'])->name('brands.identity.update');
    Route::post('/brands/{brand}/aliases/test', [BrandIdentityController::class, 'testAlias'])->middleware('throttle:alias-match-test')->name('brands.aliases.test');
    Route::post('/brands/{brand}/aliases', [BrandIdentityController::class, 'storeAlias'])->name('brands.aliases.store');
    Route::patch('/brands/{brand}/aliases/{alias}', [BrandIdentityController::class, 'updateAlias'])->whereNumber('alias')->name('brands.aliases.update');
    Route::delete('/brands/{brand}/aliases/{alias}', [BrandIdentityController::class, 'destroyAlias'])->whereNumber('alias')->name('brands.aliases.destroy');
    Route::post('/brands/{brand}/domains', [BrandIdentityController::class, 'storeDomain'])->name('brands.domains.store');
    Route::patch('/brands/{brand}/domains/{domain}', [BrandIdentityController::class, 'updateDomain'])->whereNumber('domain')->name('brands.domains.update');
    Route::delete('/brands/{brand}/domains/{domain}', [BrandIdentityController::class, 'destroyDomain'])->whereNumber('domain')->name('brands.domains.destroy');
    Route::put('/brands/{brand}/integrations/{type}', [BrandIdentityController::class, 'integration'])->whereIn('type', ['ga4', 'clickhouse_logs'])->name('brands.integrations.update');
    Route::get('/brands/{brand}/measurement-plan', [BrandMeasurementPlanController::class, 'show'])->name('brands.plan.show');
    Route::post('/brands/{brand}/measurement-plan/estimate', [BrandMeasurementPlanController::class, 'estimate'])->name('brands.plan.estimate');
    Route::put('/brands/{brand}/measurement-plan', [BrandMeasurementPlanController::class, 'update'])->name('brands.plan.update');
    Route::post('/brands/{brand}/views', [SavedViewController::class, 'store'])->name('brands.views.store');
    Route::delete('/brands/{brand}/views/{savedView}', [SavedViewController::class, 'destroy'])->name('brands.views.destroy');
    Route::get('/brands/{brand}', [BrandController::class, 'show'])->name('brands.show');
    // The sidebar's setup pages and the inbox (docs/ux-arbeidsflate.md 6.1).
    Route::get('/brands/{brand}/setup/prompts', SetupPromptController::class)->name('brands.setup.prompts');
    Route::get('/brands/{brand}/setup/competitors', SetupCompetitorController::class)->name('brands.setup.competitors');
    Route::get('/brands/{brand}/setup/markets', SetupMarketController::class)->name('brands.setup.markets');
    Route::get('/brands/{brand}/setup/identity', SetupIdentityController::class)->name('brands.setup.identity');
    Route::get('/brands/{brand}/setup/integrations', SetupIntegrationController::class)->name('brands.setup.integrations');
    Route::get('/brands/{brand}/setup/notifications', NotificationSetupController::class)->name('brands.setup.notifications');
    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');

    Route::post('/alerts/acknowledge', [AlertController::class, 'acknowledgeBulk'])->name('alerts.acknowledge.bulk');
    Route::delete('/alerts/acknowledge', [AlertController::class, 'undoBulk'])->name('alerts.unacknowledge.bulk');
    Route::post('/brands/{brand}/claims/{claim}/action', [InboxController::class, 'correct'])->name('brands.claims.action');
    Route::delete('/brands/{brand}/actions/{action}/inbox-change', [InboxController::class, 'undoAction'])->name('brands.inbox.undo-action');
    Route::post('/brands/{brand}/checklist/hide', [InboxController::class, 'hide'])->name('brands.checklist.hide');
    Route::put('/brands/{brand}/alert-rules/{type}', [AlertController::class, 'upsert'])->name('brands.alert-rules.upsert');
    Route::post('/brands/{brand}/report-schedules', [ReportController::class, 'store'])->name('brands.report-schedules.store');
    Route::put('/brands/{brand}/report-schedules/{schedule}', [ReportController::class, 'update'])->name('brands.report-schedules.update');
    Route::delete('/brands/{brand}/report-schedules/{schedule}', [ReportController::class, 'destroy'])->name('brands.report-schedules.destroy');
    Route::post('/brands/{brand}/report-schedules/{schedule}/send-test', [ReportController::class, 'sendTest'])->middleware('throttle:report-preview')->name('brands.report-schedules.test');
    Route::get('/alerts', [InboxController::class, 'legacy'])->name('alerts.index');
    Route::post('/brands/{brand}/topics', [BrandTopicController::class, 'store'])->name('brands.topics.store');
    Route::patch('/brands/{brand}/topics/{topic}', [BrandTopicController::class, 'update'])->name('brands.topics.update');
    Route::delete('/brands/{brand}/topics/{topic}', [BrandTopicController::class, 'destroy'])->name('brands.topics.destroy');
    Route::post('/brands/{brand}/topics/{topic}/merge', [BrandTopicController::class, 'merge'])->name('brands.topics.merge');
    Route::post('/brands/{brand}/topics/{topic}/suggestions', [BrandTopicController::class, 'suggestions'])->middleware('throttle:prompt-suggestions')->name('brands.topics.suggestions');
    Route::post('/brands/{brand}/markets/{market}/prompts', [PromptLibraryController::class, 'store'])->name('brands.prompts.store');
    Route::delete('/brands/{brand}/prompts/{prompt}', [PromptLibraryController::class, 'destroy'])->name('brands.prompts.destroy');
    Route::post('/brands/{brand}/prompts/bulk', [PromptLibraryController::class, 'bulk'])->name('brands.prompts.bulk');
    Route::post('/brands/{brand}/prompts/import/preview', [PromptLibraryController::class, 'preview'])->name('brands.prompts.import.preview');
    Route::get('/brands/{brand}/prompts', [BrandPromptController::class, 'index'])->name('brands.prompts.index');
    Route::get('/brands/{brand}/prompts/export', [BrandPromptController::class, 'export'])->name('brands.prompts.export');
    Route::post('/brands/{brand}/prompts/import', [BrandPromptController::class, 'import'])->name('brands.prompts.import');
    Route::get('/brands/{brand}/prompts/{prompt}', [BrandPromptController::class, 'show'])->name('brands.prompts.show');
    Route::patch('/brands/{brand}/prompts/{prompt}', [BrandPromptController::class, 'update'])->name('brands.prompts.update');
    Route::get('/brands/{brand}/responses', [BrandResponseController::class, 'index'])->name('brands.responses.index');
    Route::get('/brands/{brand}/responses/{response}/raw', [BrandPromptController::class, 'raw'])->name('brands.responses.raw');
    Route::get('/brands/{brand}/competitors', [BrandCompetitorController::class, 'index'])->name('brands.competitors.index');
    Route::post('/brands/{brand}/competitors', [BrandCompetitorController::class, 'store'])->name('brands.competitors.store');
    Route::delete('/brands/{brand}/competitors/{competitor}', [BrandCompetitorController::class, 'destroy'])->whereNumber('competitor')->name('brands.competitors.destroy');
    // U21: decisions on unknown brands and the competitor setup API (docs/ux-arbeidsflate.md 6.3).
    Route::post('/brands/{brand}/entity-decisions', [EntityDecisionController::class, 'store'])->name('brands.entities.decide');
    Route::delete('/brands/{brand}/entity-decisions/{decision}', [EntityDecisionController::class, 'destroy'])->whereNumber('decision')->name('brands.entities.undo');
    Route::get('/brands/{brand}/entity-decisions/{key}/excerpts', [EntityDecisionController::class, 'excerpts'])->where('key', '[^/]+')->name('brands.entities.excerpts');
    Route::patch('/brands/{brand}/competitors/{competitor}', [CompetitorSetupController::class, 'update'])->whereNumber('competitor')->name('brands.competitors.update');
    Route::post('/brands/{brand}/competitors/reorder', [CompetitorSetupController::class, 'reorder'])->name('brands.competitors.reorder');
    Route::post('/brands/{brand}/competitors/{competitor}/aliases', [CompetitorSetupController::class, 'storeAlias'])->whereNumber('competitor')->name('brands.competitors.aliases.store');
    Route::patch('/brands/{brand}/competitors/{competitor}/aliases/{alias}', [CompetitorSetupController::class, 'updateAlias'])->whereNumber(['competitor', 'alias'])->name('brands.competitors.aliases.update');
    Route::delete('/brands/{brand}/competitors/{competitor}/aliases/{alias}', [CompetitorSetupController::class, 'destroyAlias'])->whereNumber(['competitor', 'alias'])->name('brands.competitors.aliases.destroy');
    Route::post('/brands/{brand}/competitors/{competitor}/domains', [CompetitorSetupController::class, 'storeDomain'])->whereNumber('competitor')->name('brands.competitors.domains.store');
    Route::delete('/brands/{brand}/competitors/{competitor}/domains/{domain}', [CompetitorSetupController::class, 'destroyDomain'])->whereNumber(['competitor', 'domain'])->name('brands.competitors.domains.destroy');
    Route::get('/brands/{brand}/sources', BrandSourceController::class)->name('brands.sources');
    Route::get('/brands/{brand}/crawlers', BrandCrawlerController::class)->name('brands.crawlers');
    Route::get('/brands/{brand}/facts', [BrandFactController::class, 'index'])->name('brands.facts.index');
    Route::post('/brands/{brand}/facts', [BrandFactController::class, 'store'])->name('brands.facts.store');
    Route::patch('/brands/{brand}/facts/{fact}', [BrandFactController::class, 'update'])->name('brands.facts.update');
    Route::delete('/brands/{brand}/facts/{fact}', [BrandFactController::class, 'destroy'])->name('brands.facts.destroy');
    Route::get('/brands/{brand}/actions', [BrandActionController::class, 'index'])->name('brands.actions.index');
    Route::post('/brands/{brand}/actions', [BrandActionController::class, 'store'])->name('brands.actions.store');
    Route::get('/brands/{brand}/actions/{action}', [BrandActionController::class, 'show'])->name('brands.actions.show');
    Route::patch('/brands/{brand}/actions/{action}', [BrandActionController::class, 'update'])->name('brands.actions.update');
    Route::patch('/brands/{brand}/actions/{action}/steps/{step}', [BrandActionController::class, 'step'])->whereNumber('step')->name('brands.actions.steps');
    Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::post('/reports/{report}/links', [ReportLinkController::class, 'store'])->name('reports.links.store');
    Route::delete('/reports/{report}/links/{link}', [ReportLinkController::class, 'destroy'])->name('reports.links.destroy');
    Route::get('/reports/{report}/download', [ReportController::class, 'download'])->name('reports.download');

    Route::get('/usage', UsageController::class)->name('usage.index');

    Route::put('/teams/{team}/slack', [TeamSettingsController::class, 'updateSlack'])->name('teams.slack.update');
    Route::put('/teams/{team}/branding', [TeamSettingsController::class, 'updateBranding'])->name('teams.branding.update');
    Route::put('/teams/{team}/api-keys', [TeamSettingsController::class, 'storeApiKey'])->name('teams.api-keys.store');
    Route::put('/teams/{team}/byok-budget', [TeamSettingsController::class, 'updateBudget'])->name('teams.byok-budget.update');

    Route::get('/teams/{team}/bot-log-uploads', [BotLogUploadController::class, 'index'])->name('bot-log-uploads.index');
    Route::post('/brands/{brand}/bot-log-uploads', [BotLogUploadController::class, 'store'])->name('bot-log-uploads.store');

    Route::get('/admin/tenants', [AdminTenantController::class, 'index'])->name('admin.tenants.index');
    Route::post('/admin/tenants', [AdminTenantController::class, 'store'])->name('admin.tenants.store');
    Route::put('/admin/tenants/{team}', [AdminTenantController::class, 'update'])->name('admin.tenants.update');
    Route::get('/admin/plans', [AdminPlanController::class, 'index'])->name('admin.plans.index');
    Route::post('/admin/plans', [AdminPlanController::class, 'store'])->name('admin.plans.store');
    Route::put('/admin/plans/{plan}', [AdminPlanController::class, 'update'])->name('admin.plans.update');
    Route::get('/admin/engines', [AdminEngineController::class, 'index'])->name('admin.engines.index');
    Route::put('/admin/engines/{engine}', [AdminEngineController::class, 'update'])->name('admin.engines.update');
    Route::post('/admin/engines/{engine}/prices', [AdminEngineController::class, 'storePrice'])->name('admin.engines.prices.store');
    Route::get('/admin/usage', AdminUsageController::class)->name('admin.usage');

    // The onboarding wizard (docs/spec.md 13, ADR 0018). Proposals stay in the cache until confirm saves them.
    Route::prefix('onboarding')->name('onboarding.')->group(function () {
        Route::get('wizard', [OnboardingController::class, 'create'])->name('wizard');
        Route::post('analyses', [OnboardingController::class, 'storeBrandAnalysis'])
            ->middleware('throttle:onboarding')
            ->name('analyses.store');
        Route::get('analyses/{analysis}', [OnboardingController::class, 'show'])
            ->whereUuid('analysis')
            ->name('analyses.show');
        Route::post('analyses/{analysis}/confirm', [OnboardingController::class, 'confirm'])
            ->whereUuid('analysis')
            ->name('analyses.confirm');
        Route::post('cost-estimate', [OnboardingController::class, 'estimate'])->name('cost-estimate');
    });
    Route::get('/brands/{brand}/markets/create', [OnboardingController::class, 'createMarket'])->name('brands.markets.create');
    Route::post('/brands/{brand}/market-proposals', [OnboardingController::class, 'storeMarketAnalysis'])
        ->middleware('throttle:onboarding')
        ->name('brands.market-proposals.store');

    // Platform admin: act as a tenant (docs/spec.md 5.2). Starting needs a confirmed password.
    Route::post('/admin/acting-as/{team}', [ActingAsController::class, 'store'])
        ->middleware('password.confirm')
        ->name('admin.acting-as.start');
    Route::delete('/admin/acting-as', [ActingAsController::class, 'destroy'])
        ->name('admin.acting-as.stop');
});

// Development-only pages (chart examples). The file registers nothing outside local and testing.
require __DIR__.'/dev.php';
