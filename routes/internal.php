<?php

use App\Http\Controllers\Internal\ExtractionPollController;
use App\Http\Controllers\Internal\WorkRecoverController;
use App\Http\Controllers\Internal\WorkWakeController;
use App\Http\Middleware\AuthenticateScheduler;
use App\Http\Middleware\AuthenticateWorkTasks;
use Illuminate\Support\Facades\Route;

Route::post('/internal/tick/extraction-poll', ExtractionPollController::class)
    ->middleware(AuthenticateScheduler::class)->name('internal.extraction-poll');

// The hourly recovery check of durable work (T40-F4, ADR 0033).
Route::post('/internal/tick/work-recover', WorkRecoverController::class)
    ->middleware(AuthenticateScheduler::class)->name('internal.work-recover');

// Cloud Tasks wake-ups (T40-F4, ADR 0033): only the wake-up identity and audience; the bodies hold internal ids only.
Route::post('/internal/work/wake', [WorkWakeController::class, 'signal'])
    ->middleware(AuthenticateWorkTasks::class)->name('internal.work.wake');
Route::post('/internal/work/queue-wake', [WorkWakeController::class, 'queue'])
    ->middleware(AuthenticateWorkTasks::class)->name('internal.work.queue-wake');
