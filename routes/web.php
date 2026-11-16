<?php

use App\Http\Controllers\Admin\ActingAsController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\UsageController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\BotLogUploadController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OnboardingController;
use App\Http\Middleware\AuthenticateSessionAcrossGuards;
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

// Login with Google (docs/spec.md 5.4). Guests only, rate limited per IP.
Route::middleware(['guest', 'throttle:sso'])->prefix('auth/{provider}')->whereIn('provider', config('ekko.sso_providers'))->group(function () {
    Route::get('redirect', [SocialLoginController::class, 'redirect'])->name('social.redirect');
    Route::get('callback', [SocialLoginController::class, 'callback'])->name('social.callback');
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
    Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('/brands/{brand}', [BrandController::class, 'show'])->name('brands.show');
    Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');

    Route::get('/teams/{team}/bot-log-uploads', [BotLogUploadController::class, 'index'])->name('bot-log-uploads.index');
    Route::post('/brands/{brand}/bot-log-uploads', [BotLogUploadController::class, 'store'])->name('bot-log-uploads.store');

    Route::get('/admin/usage', UsageController::class)->name('admin.usage');

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
