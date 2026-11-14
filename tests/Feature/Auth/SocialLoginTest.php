<?php

use App\Models\SocialAccount;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

// docs/spec.md 5.4 and T07: Google and Microsoft login for invited users only. Socialite is faked,
// so no test reaches a provider.

beforeEach(function () {
    config([
        'services.google.client_id' => 'test-id',
        'services.google.client_secret' => 'test-secret',
        'services.microsoft.client_id' => 'test-id',
        'services.microsoft.client_secret' => 'test-secret',
    ]);
});

function fakeProviderUser(string $provider, string $email, string $id = 'provider-1', bool $verified = true): void
{
    $user = (new SocialiteUser)->map(['id' => $id, 'name' => 'Provider Person', 'email' => $email]);
    $user->user = ['email_verified' => $verified];

    Socialite::fake($provider, $user);
}

function invite(string $email, ?Team $team = null, ?DateTimeInterface $at = null): void
{
    $team ??= Team::factory()->tenant()->create();
    $invitation = $team->teamInvitations()->create(['email' => $email, 'role' => 'reader']);

    if ($at !== null) {
        $invitation->forceFill(['created_at' => $at])->save();
    }
}

test('an uninvited Google account gets no access and no user is created', function () {
    fakeProviderUser('google', 'stranger@example.test');
    $users = User::count();

    $this->get('/auth/google/callback')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    $this->assertGuest();
    expect(User::count())->toBe($users)
        ->and(SocialAccount::count())->toBe(0);
});

test('an uninvited Microsoft account gets no access and no user is created', function () {
    fakeProviderUser('microsoft', 'stranger@example.test');
    $users = User::count();

    $this->get('/auth/microsoft/callback')->assertRedirect(route('login'));

    $this->assertGuest();
    expect(User::count())->toBe($users);
});

test('an invited Google account creates the user, verified, and logs in', function () {
    invite('Invited@Example.test');
    fakeProviderUser('google', 'invited@example.test');

    $this->get('/auth/google/callback')->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    $user = User::where('email', 'invited@example.test')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->socialAccounts()->where('provider', 'google')->exists())->toBeTrue();
});

test('an invited Microsoft account logs in', function () {
    invite('invited@example.test');
    fakeProviderUser('microsoft', 'invited@example.test');

    $this->get('/auth/microsoft/callback');

    $this->assertAuthenticated();
});

test('an expired invitation gives no access', function () {
    invite('late@example.test', at: now()->subDays(8));
    fakeProviderUser('google', 'late@example.test');

    $this->get('/auth/google/callback')->assertRedirect(route('login'));

    $this->assertGuest();
    expect(User::where('email', 'late@example.test')->exists())->toBeFalse();
});

test('an unverified Google e-mail address is not trusted', function () {
    invite('invited@example.test');
    fakeProviderUser('google', 'invited@example.test', verified: false);

    $this->get('/auth/google/callback');

    $this->assertGuest();
    expect(User::where('email', 'invited@example.test')->exists())->toBeFalse();
});

test('an existing user is linked by e-mail and the address counts as verified', function () {
    $user = User::factory()->unverified()->create(['email' => 'known@example.test']);
    fakeProviderUser('google', 'KNOWN@example.test');

    $this->get('/auth/google/callback');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('a linked account logs in even when the e-mail address changed at the provider', function () {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'provider-1']);
    fakeProviderUser('google', 'renamed@example.test', 'provider-1');

    $this->get('/auth/google/callback');

    $this->assertAuthenticatedAs($user);
});

test('a second Google account cannot be linked to the same user', function () {
    $user = User::factory()->create(['email' => 'known@example.test']);
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'first']);
    fakeProviderUser('google', 'known@example.test', 'second');

    $this->get('/auth/google/callback');

    $this->assertGuest();
});

test('a user with confirmed two-factor authentication goes to the challenge', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'secure@example.test']);
    fakeProviderUser('google', 'secure@example.test');

    $this->get('/auth/google/callback')->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    $this->assertSame($user->id, session('login.id'));
});

test('a provider without credentials is not available', function () {
    config(['services.google.client_secret' => null]);

    $this->get('/auth/google/redirect')->assertNotFound();
    $this->get('/auth/google/callback')->assertNotFound();
});

test('an unknown provider is not available', function () {
    $this->get('/auth/github/redirect')->assertNotFound();
});

test('the redirect sends the browser to the provider', function () {
    $this->get('/auth/google/redirect')->assertRedirectContains('accounts.google.com');
    $this->get('/auth/microsoft/redirect')->assertRedirectContains('login.microsoftonline.com');
});

test('the login page offers only configured providers', function () {
    config(['services.microsoft.client_id' => null]);

    $this->get('/login')->assertInertia(fn ($page) => $page->where('ssoProviders', ['google']));
});

test('a signed-in user is sent away from the callback', function () {
    $this->actingAs(User::factory()->create())->get('/auth/google/callback')->assertRedirect();
});

test('the single sign-on endpoints are rate limited per IP', function () {
    RateLimiter::clear('sso');

    foreach (range(1, 10) as $_) {
        $this->get('/auth/unknown/redirect');
    }
    // The unknown provider never reaches the limiter, so use real routes.
    foreach (range(1, 10) as $_) {
        $this->get('/auth/google/redirect')->assertRedirect();
    }

    $this->get('/auth/google/redirect')->assertStatus(429);
});
