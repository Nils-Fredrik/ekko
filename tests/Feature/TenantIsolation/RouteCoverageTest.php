<?php

use Illuminate\Support\Facades\Route;
use Tests\Feature\TenantIsolation\Support\RouteAccess;
use Tests\Feature\TenantIsolation\Support\RouteRegister;

// T06 acceptance criterion: a new route without a policy fails CI. Every route must be in the
// register with its access class, and every route that takes a parameter must say how another
// tenant's or user's resource is tested.

test('every registered route is in the route access register', function () {
    $missing = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $key = RouteRegister::keyFor($route);
        if (! array_key_exists($key, RouteRegister::entries())) {
            $missing[] = $key.' ('.implode('|', $route->methods()).' '.$route->uri().')';
        }
    }

    expect($missing)->toBe([], "Routes without an entry in tests/Feature/TenantIsolation/routes.php:\n".implode("\n", $missing));
});

test('the register has no entries for routes that no longer exist', function () {
    $registered = array_map(fn ($route) => RouteRegister::keyFor($route), Route::getRoutes()->getRoutes());

    expect(array_values(array_diff(array_keys(RouteRegister::entries()), $registered)))->toBe([]);
});

test('every route with a parameter that needs a login says how foreign resources are tested', function () {
    $unchecked = [];
    foreach (RouteRegister::entries() as $key => $access) {
        $route = RouteRegister::route($key);
        if ($access->requiresLogin()
            && $access->kind !== RouteAccess::PLATFORM_ADMIN
            && $route->parameterNames() !== []
            && $access->foreign === null
            && $access->noForeignCheck === null) {
            $unchecked[] = $key;
        }
    }

    expect($unchecked)->toBe([]);
});

test('every tenant route can be tested against another tenant', function () {
    foreach (RouteRegister::entries() as $key => $access) {
        if ($access->kind === RouteAccess::TENANT) {
            expect($access->foreign)->not->toBeNull("{$key} needs a foreign request");
        }
    }
});

test('every tenant route with a parameter has an allowed request in the own tenant', function () {
    foreach (RouteRegister::entries() as $key => $access) {
        if ($access->kind === RouteAccess::TENANT && RouteRegister::route($key)->parameterNames() !== []) {
            expect($access->own)->not->toBeNull("{$key} needs an own request (positive control)");
        }
    }
});
