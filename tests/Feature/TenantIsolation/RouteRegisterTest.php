<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\Feature\TenantIsolation\Support\RouteAccess;
use Tests\Feature\TenantIsolation\Support\RouteRegister;

// The register must not let a mistake pass as a 403 or 404: a parameter the route does not have,
// or a missing parameter on a signed-in route, is an error.

test('a parameter the route does not have is an error', function () {
    RouteRegister::url(RouteRegister::route('teams.show'), ['taem' => 1], RouteAccess::tenant(fn () => []));
})->throws(RuntimeException::class, "has no parameter 'taem'");

test('a missing parameter on a signed-in route is an error', function () {
    RouteRegister::url(RouteRegister::route('teams.show'), [], RouteAccess::tenant(fn () => []));
})->throws(RuntimeException::class, "needs parameter 'team'");

test('a signed route gets a valid signature', function () {
    $url = RouteRegister::url(RouteRegister::route('team-invitations.accept'), ['invitation' => 1], RouteAccess::user(fn () => [])->signed());

    expect($url)->toContain('/team-invitations/1?')->toContain('signature=');

    // And the signature is valid for the request the test makes.
    expect(URL::hasValidSignature(Request::create($url)))->toBeTrue();
});

test('unnamed routes are keyed by all their methods', function () {
    expect(array_key_exists('GET up', RouteRegister::entries()))->toBeTrue();
});
