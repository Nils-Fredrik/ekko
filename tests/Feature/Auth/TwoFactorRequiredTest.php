<?php

use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// docs/spec.md 5.4 and T07: two-factor authentication is required for the platform admin and for
// partner advisors, and optional for everyone else.

function partnerAdvisor(bool $twoFactor): User
{
    $user = User::factory()->withPersonalTeam()->withTwoFactor($twoFactor)->create();
    $team = Team::factory()->tenant()->create();
    $team->users()->attach($user, ['role' => 'partner_advisor']);
    $user->switchTeam($team);

    return $user->fresh();
}

test('a platform admin without two-factor authentication is sent to set it up', function () {
    $admin = User::factory()->platformAdmin(false)->withPersonalTeam()->create();

    $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('profile.show'));
});

test('a partner advisor without two-factor authentication is sent to set it up', function () {
    $this->actingAs(partnerAdvisor(false))->get('/dashboard')->assertRedirect(route('profile.show'));
});

test('a partner advisor in any tenant is covered, not just the current one', function () {
    $user = partnerAdvisor(false);
    $user->switchTeam($user->personalTeam());

    $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('profile.show'));
});

test('the pages that set up two-factor authentication stay reachable', function () {
    $admin = User::factory()->platformAdmin(false)->withPersonalTeam()->create();

    $this->actingAs($admin)->get('/user/profile')->assertOk();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/user/two-factor-authentication')->assertRedirect();
    expect($admin->fresh()->two_factor_secret)->not->toBeNull();

    $this->actingAs($admin)->post('/logout')->assertRedirect();
});

test('two-factor authentication that is enabled but not confirmed does not count', function () {
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->create();
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('profile.show'));
});

test('a platform admin with two-factor authentication gets in', function () {
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->withTwoFactor()->create();

    $this->actingAs($admin)->get('/dashboard')->assertOk();
});

test('a partner advisor with two-factor authentication gets in', function () {
    $this->actingAs(partnerAdvisor(true))->get('/dashboard')->assertOk();
});

test('two-factor authentication is optional for admin, editor and reader', function (string $role) {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->tenant()->create();
    $team->users()->attach($user, ['role' => $role]);
    $user->switchTeam($team);

    $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
})->with(['admin', 'editor', 'reader']);

test('the API refuses a partner advisor without two-factor authentication', function () {
    Sanctum::actingAs(partnerAdvisor(false), ['read']);

    $this->getJson('/api/user')->assertForbidden();
});

test('the API answers a partner advisor with two-factor authentication', function () {
    Sanctum::actingAs(partnerAdvisor(true), ['read']);

    $this->getJson('/api/user')->assertOk();
});

test('a JSON request answers 403 instead of redirecting', function () {
    $admin = User::factory()->platformAdmin(false)->withPersonalTeam()->create();

    $this->actingAs($admin)->getJson('/dashboard')->assertForbidden();
});

test('password login is limited to 5 attempts a minute per e-mail and IP', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429);
});

test('password login by a user with confirmed two-factor authentication goes through the challenge', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

// T07-F1: the owner can switch the requirement off while testing before launch. It is on by default.
test('the requirement is on in the test environment', function () {
    expect(config('ekko.require_two_factor'))->toBeTrue()
        ->and(User::factory()->platformAdmin(false)->create()->requiresTwoFactor())->toBeTrue();
});

test('with the requirement switched off, a platform admin and a partner advisor without two-factor get in', function () {
    config(['ekko.require_two_factor' => false]);
    $admin = User::factory()->platformAdmin(false)->withPersonalTeam()->create();
    $advisor = partnerAdvisor(false);

    expect($admin->requiresTwoFactor())->toBeFalse()
        ->and($advisor->requiresTwoFactor())->toBeFalse();
    $this->actingAs($admin)->get('/dashboard')->assertOk();
    $this->actingAs($advisor)->get('/dashboard')->assertOk();
});

test('with the requirement switched off, the API answers a partner advisor without two-factor', function () {
    config(['ekko.require_two_factor' => false]);
    Sanctum::actingAs(partnerAdvisor(false), ['read']);

    $this->getJson('/api/user')->assertOk();
});

test('only an explicit false switches the requirement off', function (?string $value, bool $required) {
    $previous = [$_ENV['EKKO_REQUIRE_TWO_FACTOR'] ?? null, $_SERVER['EKKO_REQUIRE_TWO_FACTOR'] ?? null];
    try {
        if ($value === null) {
            unset($_ENV['EKKO_REQUIRE_TWO_FACTOR'], $_SERVER['EKKO_REQUIRE_TWO_FACTOR']);
        } else {
            $_ENV['EKKO_REQUIRE_TWO_FACTOR'] = $_SERVER['EKKO_REQUIRE_TWO_FACTOR'] = $value;
        }

        expect((require config_path('ekko.php'))['require_two_factor'])->toBe($required);
    } finally {
        foreach (['_ENV', '_SERVER'] as $index => $global) {
            if ($previous[$index] === null) {
                unset($GLOBALS[$global]['EKKO_REQUIRE_TWO_FACTOR']);
            } else {
                $GLOBALS[$global]['EKKO_REQUIRE_TWO_FACTOR'] = $previous[$index];
            }
        }
    }
})->with([
    'not set' => [null, true],
    'empty' => ['', true],
    '(empty)' => ['(empty)', true],
    'true' => ['true', true],
    'unknown' => ['nei', true],
    'false' => ['false', false],
    '(false)' => ['(false)', false],
]);

test('with the requirement switched off, two-factor that is set up is still asked for at login', function () {
    config(['ekko.require_two_factor' => false]);
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->withTwoFactor()->create();

    $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});
