<?php

use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

// U02 and U18: the chart and component examples exist in development and testing, never in production.

test('a signed-in user sees the chart examples in the testing environment', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->get('/dev/charts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dev/Charts'));
});

test('a signed-in user sees the component examples in the testing environment', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->get('/dev/components')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dev/Components'));
});

test('the development routes are not registered in production', function () {
    $router = new Router(app('events'), app());
    Route::swap($router);
    app()->instance('router', $router);
    app()['env'] = 'production';

    try {
        require base_path('routes/dev.php');
        expect(count($router->getRoutes()->getRoutes()))->toBe(0);
    } finally {
        app()['env'] = 'testing';
    }
});

test('the development routes are registered locally', function () {
    $router = new Router(app('events'), app());
    Route::swap($router);
    app()->instance('router', $router);
    app()['env'] = 'local';

    try {
        require base_path('routes/dev.php');
        expect(array_map(fn ($route) => $route->getName(), $router->getRoutes()->getRoutes()))->toBe(['dev.charts', 'dev.components']);
    } finally {
        app()['env'] = 'testing';
    }
});
