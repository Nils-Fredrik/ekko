<?php

use App\Http\Controllers\Admin\ActingAsController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\BotLogUploadController;
use App\Http\Controllers\LocaleController;
use App\Models\BotLogUpload;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard', [
            'botLogUploadsUrl' => Gate::allows('create', BotLogUpload::class)
                ? route('bot-log-uploads.index', ['team' => current_tenant_id()])
                : null,
        ]);
    })->name('dashboard');

    Route::get('/teams/{team}/bot-log-uploads', [BotLogUploadController::class, 'index'])->name('bot-log-uploads.index');
    Route::post('/brands/{brand}/bot-log-uploads', [BotLogUploadController::class, 'store'])->name('bot-log-uploads.store');

    // Platform admin: act as a tenant (docs/spec.md 5.2). Starting needs a confirmed password.
    Route::post('/admin/acting-as/{team}', [ActingAsController::class, 'store'])
        ->middleware('password.confirm')
        ->name('admin.acting-as.start');
    Route::delete('/admin/acting-as', [ActingAsController::class, 'destroy'])
        ->name('admin.acting-as.stop');
});
