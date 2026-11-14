<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('tests run against PostgreSQL and never the development database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
});

test('guests are redirected from the root to the login page', function () {
    $this->get('/')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users reach the dashboard', function () {
    $this->actingAs(User::factory()->withPersonalTeam()->create())
        ->get('/dashboard')
        ->assertOk();
});

test('the default locale is Norwegian Bokmål and is shared with the frontend', function () {
    expect(config('app.locale'))->toBe('nb')
        ->and(config('app.fallback_locale'))->toBe('nb');

    $this->actingAs(User::factory()->withPersonalTeam()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('locale', 'nb'));
});

test('the database session runs in UTC, whatever the server time zone', function () {
    expect(DB::selectOne('SHOW TIMEZONE')->TimeZone)->toBe('UTC');
});
