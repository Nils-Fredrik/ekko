<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// No public landing page: guests are sent to the login page by the auth middleware.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
});
