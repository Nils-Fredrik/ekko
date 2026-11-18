<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

// T07-F2: a user with a linked Google account confirms with Google instead of a password, which a user
// who signed up with Google does not know. Socialite is faked for the callback, so no test reaches Google.

beforeEach(function () {
    config([
        'services.google.client_id' => 'test-id',
        'services.google.client_secret' => 'test-secret',
    ]);
});

function googleLinkedUser(string $providerUserId = 'google-1', bool $platformAdmin = false): User
{
    $factory = User::factory()->withPersonalTeam();
    $user = ($platformAdmin ? $factory->platformAdmin(false) : $factory)->create();
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => $providerUserId]);

    return $user;
}

function googleReturns(string $providerUserId): void
{
    Socialite::fake('google', (new SocialiteUser)->map(['id' => $providerUserId, 'name' => 'Someone', 'email' => 'someone@example.test']));
}

function pendingConfirmation(User $user, string $provider = 'google'): array
{
    return ['sso.confirm' => ['user_id' => $user->getKey(), 'provider' => $provider]];
}

test('confirming sends a user with a linked account to Google, which is asked to authenticate again', function () {
    $user = googleLinkedUser();

    $response = $this->actingAs($user)->from('/user/profile')->get('/auth/google/confirm');

    $response->assertRedirectContains('accounts.google.com')
        ->assertRedirectContains('prompt=select_account')
        ->assertRedirectContains('max_age=0');
    expect(session('sso.confirm'))->toBe(['user_id' => $user->id, 'provider' => 'google'])
        ->and(session('url.intended'))->toBe(url('/user/profile'));
});

test('a user without a linked account cannot start a confirmation', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->get('/auth/google/confirm')->assertRedirect(route('profile.show'));

    expect(session('sso.confirm'))->toBeNull();
});

test('a guest cannot start a confirmation', function () {
    $this->get('/auth/google/confirm')->assertRedirect(route('login'));
});

test('the linked account confirms the password, and the user can then enable two-factor authentication', function () {
    $user = googleLinkedUser();
    googleReturns('google-1');

    $this->actingAs($user)->withSession([...pendingConfirmation($user), 'url.intended' => url('/user/profile')])
        ->get('/auth/google/callback')
        ->assertRedirect(url('/user/profile'));

    expect(session('auth.password_confirmed_at'))->toBeInt()
        ->and(session('sso.confirm'))->toBeNull();

    $this->post('/user/two-factor-authentication')->assertRedirect();
    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

test('another Google account confirms nothing', function () {
    $user = googleLinkedUser('google-1');
    googleLinkedUser('google-2');
    googleReturns('google-2');

    $this->actingAs($user)->withSession(pendingConfirmation($user))
        ->get('/auth/google/callback')
        ->assertRedirect(route('profile.show'));

    expect(session('auth.password_confirmed_at'))->toBeNull();
    $this->post('/user/two-factor-authentication')->assertRedirect(route('password.confirm'));
    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('without a confirmation this session started, the callback confirms nothing for a signed-in user', function (?string $startedFor) {
    $user = googleLinkedUser();
    $other = googleLinkedUser('google-9');
    googleReturns('google-1');
    $session = match ($startedFor) {
        'other user' => pendingConfirmation($other),
        'other provider' => pendingConfirmation($user, 'microsoft'),
        default => [],
    };

    $this->actingAs($user)->withSession($session)->get('/auth/google/callback')->assertRedirect(config('fortify.home'));

    expect(session('auth.password_confirmed_at'))->toBeNull();
})->with(['none' => null, 'other user' => 'other user', 'other provider' => 'other provider']);

test('a failed provider call confirms nothing', function () {
    $user = googleLinkedUser();
    Socialite::shouldReceive('driver->user')->andThrow(new RuntimeException('provider down'));

    $this->actingAs($user)->withSession(pendingConfirmation($user))
        ->get('/auth/google/callback')
        ->assertRedirect(route('profile.show'));

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('a platform admin who must set up two-factor authentication can confirm with Google', function () {
    $admin = googleLinkedUser(platformAdmin: true);

    $this->actingAs($admin)->get('/auth/google/confirm')->assertRedirectContains('accounts.google.com');

    googleReturns('google-1');
    $this->get('/auth/google/callback')->assertRedirect();
    expect(session('auth.password_confirmed_at'))->toBeInt();
});

test('pages say which providers the signed-in user can confirm with', function () {
    $linked = googleLinkedUser();
    $unlinked = User::factory()->withPersonalTeam()->create();

    $this->actingAs($linked)->get('/user/profile')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('ssoConfirmProviders', ['google']));
    $this->actingAs($unlinked)->get('/user/profile')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('ssoConfirmProviders', []));

    config(['services.google.client_secret' => null]);
    $this->actingAs($linked)->get('/user/profile')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('ssoConfirmProviders', []));
});
