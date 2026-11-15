<?php

use App\Http\Controllers\Admin\ActingAsController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\BrandCompetitorController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\BrandCrawlerController;
use App\Http\Controllers\BrandPromptController;
use App\Http\Controllers\BrandSourceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
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
    Route::get('/brands/{brand}/prompts', [BrandPromptController::class, 'index'])->name('brands.prompts.index');
    Route::get('/brands/{brand}/prompts/export', [BrandPromptController::class, 'export'])->name('brands.prompts.export');
    Route::post('/brands/{brand}/prompts/import', [BrandPromptController::class, 'import'])->name('brands.prompts.import');
    Route::get('/brands/{brand}/prompts/{prompt}', [BrandPromptController::class, 'show'])->name('brands.prompts.show');
    Route::patch('/brands/{brand}/prompts/{prompt}', [BrandPromptController::class, 'update'])->name('brands.prompts.update');
    Route::get('/brands/{brand}/responses/{response}/raw', [BrandPromptController::class, 'raw'])->name('brands.responses.raw');
    Route::get('/brands/{brand}/competitors', [BrandCompetitorController::class, 'index'])->name('brands.competitors.index');
    Route::post('/brands/{brand}/competitors', [BrandCompetitorController::class, 'store'])->name('brands.competitors.store');
    Route::get('/brands/{brand}/sources', BrandSourceController::class)->name('brands.sources');
    Route::get('/brands/{brand}/crawlers', BrandCrawlerController::class)->name('brands.crawlers');

    // Platform admin: act as a tenant (docs/spec.md 5.2). Starting needs a confirmed password.
    Route::post('/admin/acting-as/{team}', [ActingAsController::class, 'store'])
        ->middleware('password.confirm')
        ->name('admin.acting-as.start');
    Route::delete('/admin/acting-as', [ActingAsController::class, 'destroy'])
        ->name('admin.acting-as.stop');
});
