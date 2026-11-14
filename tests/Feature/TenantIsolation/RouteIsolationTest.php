<?php

use Illuminate\Testing\TestResponse;
use Tests\Feature\TenantIsolation\Support\RouteAccess;
use Tests\Feature\TenantIsolation\Support\RouteRegister;
use Tests\Feature\TenantIsolation\Support\Scenario;
use Tests\TestCase;

// docs/spec.md 5.3 and T06: generated from the route access register, so every route is checked
// without a hand-written test. No route may return data from another tenant, and no request may
// change anything in another tenant.

function registerKeys(callable $filter): array
{
    $keys = array_keys(array_filter(RouteRegister::entries(), $filter));

    return array_combine($keys, array_map(fn ($key) => [$key], $keys));
}

/**
 * @param  array{params?: array<string, mixed>, data?: array<string, mixed>}  $request
 */
function callRoute(TestCase $test, string $method, string $key, array $request): TestResponse
{
    $route = RouteRegister::route($key);
    $url = RouteRegister::url($route, $request['params'] ?? []);

    return $test->json($method, $url, $request['data'] ?? []);
}

beforeEach(function () {
    $this->s = Scenario::create();
});

test('guests are refused', function (string $key) {
    $access = RouteRegister::entries()[$key];
    $request = ($access->foreign ?? $access->own ?? fn () => [])($this->s);

    foreach (RouteRegister::methods(RouteRegister::route($key)) as $method) {
        $status = callRoute($this, $method, $key, $request)->status();

        expect($status)->toBeIn([401, 403], "{$method} {$key} answered {$status} to a guest");
    }
})->with(fn () => registerKeys(fn (RouteAccess $a) => $a->requiresLogin()));

test('a user of tenant A cannot reach tenant B or another user', function (string $key) {
    $access = RouteRegister::entries()[$key];
    $before = $this->s->snapshotOfB();

    foreach (RouteRegister::methods(RouteRegister::route($key)) as $method) {
        $this->actingAs($this->s->adminA)->withSession(['auth.password_confirmed_at' => time()]);
        $status = callRoute($this, $method, $key, ($access->foreign)($this->s))->status();

        expect($status)->toBeIn([403, 404], "{$method} {$key} answered {$status} for another tenant's resource");
        expect($this->s->snapshotOfB())->toBe($before, "{$method} {$key} changed tenant B");
    }
})->with(fn () => registerKeys(fn (RouteAccess $a) => $a->foreign !== null));

test('roles without the right in their own tenant get 403', function (string $key, string $role) {
    $access = RouteRegister::entries()[$key];

    foreach (RouteRegister::methods(RouteRegister::route($key)) as $method) {
        $this->actingAs($this->s->userWithRole($role))->withSession(['auth.password_confirmed_at' => time()]);
        $status = callRoute($this, $method, $key, ($access->own)($this->s))->status();

        expect($status)->toBe(403, "{$method} {$key} answered {$status} for role {$role}");
    }
})->with(function () {
    $cases = [];
    foreach (RouteRegister::entries() as $key => $access) {
        foreach ($access->deniedRoles as $role) {
            $cases["{$key} as {$role}"] = [$key, $role];
        }
    }

    return $cases;
});

test('platform admin routes refuse everyone else', function (string $key, string $role) {
    $access = RouteRegister::entries()[$key];
    $request = ($access->own ?? fn () => [])($this->s);

    foreach (RouteRegister::methods(RouteRegister::route($key)) as $method) {
        $this->actingAs($this->s->userWithRole($role))->withSession(['auth.password_confirmed_at' => time()]);
        $status = callRoute($this, $method, $key, $request)->status();

        expect($status)->toBe(403, "{$method} {$key} answered {$status} for role {$role}");
    }
})->with(function () {
    $cases = [];
    foreach (RouteRegister::entries() as $key => $access) {
        if ($access->kind === RouteAccess::PLATFORM_ADMIN) {
            foreach (['owner', 'admin', 'partner_advisor', 'reader'] as $role) {
                $cases["{$key} as {$role}"] = [$key, $role];
            }
        }
    }

    return $cases;
});

test('no signed-in page shows anything from another tenant', function () {
    $checked = 0;

    foreach (RouteRegister::entries() as $key => $access) {
        $route = RouteRegister::route($key);
        if (! $access->requiresLogin() || $access->kind === RouteAccess::PLATFORM_ADMIN || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $request = ($access->own ?? fn () => [])($this->s);
        $this->actingAs($this->s->adminA)->withSession(['auth.password_confirmed_at' => time()]);
        $response = $this->get(RouteRegister::url($route, $request['params'] ?? []));
        $content = (string) $response->getContent();

        expect($response->status())->toBeLessThan(500, "GET {$key} answered {$response->status()}");
        expect(stripos($content, $this->s->marker))->toBeFalse("GET {$key} showed data from tenant B");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});
