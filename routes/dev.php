<?php

use Illuminate\Support\Facades\Route;

// Pages for development only: the chart examples (docs/ui-forbedring.md, U02) and the shared
// components (docs/ux-arbeidsflate.md, U18). They are registered in the local and testing
// environments, never in production.
if (! app()->environment(['local', 'testing'])) {
    return;
}

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::inertia('/dev/charts', 'Dev/Charts')->name('dev.charts');
    // Shared components with fixed test data (docs/ux-arbeidsflate.md 4.3, U18).
    Route::inertia('/dev/components', 'Dev/Components')->name('dev.components');
});
