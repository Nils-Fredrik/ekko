<?php

use App\Http\Controllers\Internal\ExtractionPollController;
use App\Http\Middleware\AuthenticateScheduler;
use Illuminate\Support\Facades\Route;

Route::post('/internal/tick/extraction-poll', ExtractionPollController::class)
    ->middleware(AuthenticateScheduler::class)->name('internal.extraction-poll');
