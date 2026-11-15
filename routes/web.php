<?php

use App\Http\Controllers\Admin\ActingAsController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OnboardingController;
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
    Route::get('/dashboard', HomeController::class)->name('dashboard');
    Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('/brands/{brand}', [BrandController::class, 'show'])->name('brands.show');

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
