<?php

use Illuminate\Support\Facades\Route;

// Pages for development only, such as the chart examples (docs/ui-forbedring.md, U02). They are
// registered in the local and testing environments, never in production.
if (! app()->environment(['local', 'testing'])) {
    return;
}

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::inertia('/dev/charts', 'Dev/Charts')->name('dev.charts');
});
