<?php

use App\Http\Controllers\Admin\ActingAsController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\UsageController as AdminUsageController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\BotLogUploadController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\BrandSettingsController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TeamSettingsController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

// No public landing page: guests are sent to the login page by the auth middleware.
Route::redirect('/', '/dashboard')->name('home');

// Language, including on the login page before anyone is signed in (docs/spec.md 14.1).
Route::put('/locale', [LocaleController::class, 'update'])->name('locale.update');

// Login with Google (docs/spec.md 5.4). Guests only, rate limited per IP.
Route::middleware(['guest', 'throttle:sso'])->prefix('auth/{provider}')->whereIn('provider', config('ekko.sso_providers'))->group(function () {
    Route::get('redirect', [SocialLoginController::class, 'redirect'])->name('social.redirect');
    Route::get('callback', [SocialLoginController::class, 'callback'])->name('social.callback');
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
    Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('/brands/{brand}/settings', [BrandSettingsController::class, 'show'])->name('brands.settings');
    Route::put('/brands/{brand}/settings', [BrandSettingsController::class, 'update'])->name('brands.settings.update');
    Route::get('/brands/{brand}', [BrandController::class, 'show'])->name('brands.show');

    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/rules', [AlertController::class, 'storeRule'])->name('alerts.rules.store');
    Route::put('/alerts/rules/{alertRule}', [AlertController::class, 'updateRule'])->name('alerts.rules.update');
    Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('/reports/schedules', [ReportController::class, 'store'])->name('reports.schedules.store');
    Route::put('/reports/schedules/{schedule}', [ReportController::class, 'update'])->name('reports.schedules.update');
    Route::get('/reports/{report}/download', [ReportController::class, 'download'])->name('reports.download');

    Route::get('/usage', UsageController::class)->name('usage.index');

    Route::put('/teams/{team}/slack', [TeamSettingsController::class, 'updateSlack'])->name('teams.slack.update');
    Route::put('/teams/{team}/branding', [TeamSettingsController::class, 'updateBranding'])->name('teams.branding.update');
    Route::put('/teams/{team}/api-keys', [TeamSettingsController::class, 'storeApiKey'])->name('teams.api-keys.store');
    Route::put('/teams/{team}/byok-budget', [TeamSettingsController::class, 'updateBudget'])->name('teams.byok-budget.update');

    Route::get('/teams/{team}/bot-log-uploads', [BotLogUploadController::class, 'index'])->name('bot-log-uploads.index');
    Route::post('/brands/{brand}/bot-log-uploads', [BotLogUploadController::class, 'store'])->name('bot-log-uploads.store');

    Route::get('/admin/usage', AdminUsageController::class)->name('admin.usage');

    // The onboarding wizard's backend (docs/spec.md 13, ADR 0018): proposals as JSON, nothing is saved.
    Route::prefix('onboarding')->name('onboarding.')->group(function () {
        Route::post('analyses', [OnboardingController::class, 'storeBrandAnalysis'])
            ->middleware('throttle:onboarding')
            ->name('analyses.store');
        Route::get('analyses/{analysis}', [OnboardingController::class, 'show'])
            ->whereUuid('analysis')
            ->name('analyses.show');
        Route::post('cost-estimate', [OnboardingController::class, 'estimate'])->name('cost-estimate');
    });
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
