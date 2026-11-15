<?php

use App\Tenancy\ActingAs;
use Illuminate\Support\Facades\Auth;
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
    // With the entry: strict parameters, and a valid signature for signed routes.
    $url = RouteRegister::url($route, $request['params'] ?? [], RouteRegister::entries()[$key]);

    if (str_starts_with($route->uri(), 'api/v1/')) {
        $user = Auth::user();
        $token = $user?->createToken('Isolation test', ['read', 'write', 'tenant:'.$user->current_team_id]);
        Auth::forgetGuards();
        Auth::shouldUse('web');
        try {
            return $test->json($method, $url, $request['data'] ?? [], $token === null ? [] : ['Authorization' => 'Bearer '.$token->plainTextToken]);
        } finally {
            Auth::forgetGuards();
            Auth::shouldUse('web');
        }
    }

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

test('no user of tenant A can reach tenant B or another user, whatever the role', function (string $key) {
    $access = RouteRegister::entries()[$key];
    $before = $this->s->snapshotOfB();

    // Every role, including the partner advisor, who legitimately reaches A's client tenant but
    // must never reach B.
    foreach (RouteAccess::TENANT_ROLES as $role) {
        foreach (RouteRegister::methods(RouteRegister::route($key)) as $method) {
            $this->actingAs($this->s->userWithRole($role))->withSession(['auth.password_confirmed_at' => time()]);
            $status = callRoute($this, $method, $key, ($access->foreign)($this->s))->status();

            expect($status)->toBeIn([403, 404], "{$method} {$key} as {$role} answered {$status} for another tenant's resource");
            expect($this->s->snapshotOfB())->toBe($before, "{$method} {$key} as {$role} changed tenant B");
        }
    }
})->with(fn () => registerKeys(fn (RouteAccess $a) => $a->foreign !== null));

test('the allowed request succeeds, so a 403 or 404 elsewhere means something', function (string $key) {
    $access = RouteRegister::entries()[$key];

    $actor = $access->kind === RouteAccess::PLATFORM_ADMIN
        ? 'platform_admin'
        : (array_values(array_diff(RouteAccess::TENANT_ROLES, $access->deniedRoles))[0] ?? 'platform_admin');

    foreach (RouteRegister::methods(RouteRegister::route($key)) as $method) {
        $this->s = Scenario::create();
        $user = $this->s->userWithRole($actor);
        if ($actor === 'platform_admin' && $access->kind === RouteAccess::TENANT) {
            app(ActingAs::class)->start($user, $this->s->tenantA, 'Isolation positive control');
        }

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $status = callRoute($this, $method, $key, ($access->own ?? fn () => [])($this->s))->status();

        // Below 400: a 422 or 409 could come from validation before authorization, or from
        // outdated data in the register, and would say nothing about the policy.
        expect($status)->toBeLessThan(400, "{$method} {$key} as {$actor} answered {$status} to an allowed request");
    }
})->with(fn () => registerKeys(fn (RouteAccess $a) => $a->own !== null || $a->kind === RouteAccess::PLATFORM_ADMIN));

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
            foreach (RouteAccess::TENANT_ROLES as $role) {
                $cases["{$key} as {$role}"] = [$key, $role];
            }
        }
    }

    return $cases;
});

test('no signed-in page shows anything from another tenant', function () {
    $checked = 0;

    foreach (['owner', 'partner_advisor', 'reader'] as $role) {
        foreach (RouteRegister::entries() as $key => $access) {
            $route = RouteRegister::route($key);
            if (! $access->requiresLogin() || $access->kind === RouteAccess::PLATFORM_ADMIN || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $request = ($access->own ?? fn () => [])($this->s);
            $this->actingAs($this->s->userWithRole($role))->withSession(['auth.password_confirmed_at' => time()]);
            // Signed routes are not scanned: today the only one (team-invitations.accept) changes data
            // and is covered by the foreign check with a valid signature. A signed GET page that only
            // shows data needs its own scan with a signed URL.
            $response = str_starts_with($route->uri(), 'api/v1/')
                ? callRoute($this, 'GET', $key, $request)
                : $this->get(RouteRegister::url($route, $request['params'] ?? [], $access->signed ? null : $access));
            $content = (string) $response->getContent();

            expect($response->status())->toBeLessThan(500, "GET {$key} as {$role} answered {$response->status()}");
            expect(stripos($content, $this->s->marker))->toBeFalse("GET {$key} as {$role} showed data from tenant B");
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(0);
});
