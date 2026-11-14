<?php

use App\Models\SocialAccount;
use App\Models\Team;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

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

function fakeProviderUser(string $provider, string $email, string $id = 'provider-1', bool $verified = true, ?string $claimEmail = null): void
{
    $user = (new SocialiteUser)->map(['id' => $id, 'name' => 'Provider Person', 'email' => $email]);
    $user->user = ['email_verified' => $verified];

    if ($provider === 'microsoft') {
        // Microsoft proves the address only with the xms_edov claim of the validated ID token.
        $driver = Mockery::mock(MicrosoftProvider::class);
        $driver->shouldReceive('user')->andReturn($user);
        $driver->shouldReceive('getClaims')->andReturn((object) ($verified ? ['xms_edov' => true, 'email' => $claimEmail ?? $email] : ['email' => $claimEmail ?? $email]));
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($driver);

        return;
    }

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

test('an existing user with a verified address is linked by e-mail', function () {
    $user = User::factory()->create(['email' => 'known@example.test']);
    fakeProviderUser('google', 'KNOWN@example.test');

    $this->get('/auth/google/callback');

    $this->assertAuthenticatedAs($user);
    expect($user->socialAccounts()->count())->toBe(1);
});

test('an existing user with an unverified address is refused, linked to nothing and not verified', function () {
    $user = User::factory()->unverified()->create(['email' => 'known@example.test']);
    fakeProviderUser('google', 'known@example.test');

    $this->get('/auth/google/callback')->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and(SocialAccount::count())->toBe(0);
});

test('finding 140 variant: a user who changed e-mail to an invited address cannot take over the invitee login', function () {
    invite('victim@example.test');
    $attacker = User::factory()->create(['email' => 'attacker@example.test']);
    $this->actingAs($attacker)->put('/user/profile-information', ['name' => $attacker->name, 'email' => 'victim@example.test']);
    auth()->logout();
    fakeProviderUser('google', 'victim@example.test');

    $this->get('/auth/google/callback')->assertRedirect(route('login'));

    $this->assertGuest();
    expect($attacker->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and(SocialAccount::count())->toBe(0);
});

test('a refused second account at the same provider changes nothing on the user', function () {
    $user = User::factory()->create(['email' => 'known@example.test', 'name' => 'Before']);
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'first']);
    $before = $user->fresh()->updated_at;
    fakeProviderUser('google', 'known@example.test', 'second');

    $this->travel(5)->minutes();
    $this->get('/auth/google/callback');

    $this->assertGuest();
    expect($user->fresh()->updated_at->equalTo($before))->toBeTrue()
        ->and($user->socialAccounts()->count())->toBe(1);
});

test('Microsoft without xms_edov gets no link to an existing user', function () {
    $user = User::factory()->create(['email' => 'known@example.test']);
    fakeProviderUser('microsoft', 'known@example.test', verified: false);

    $this->get('/auth/microsoft/callback')->assertRedirect(route('login'));

    $this->assertGuest();
    expect(SocialAccount::count())->toBe(0);
});

test('Microsoft without xms_edov gets no user from an invitation', function () {
    invite('invited@example.test');
    fakeProviderUser('microsoft', 'invited@example.test', verified: false);

    $this->get('/auth/microsoft/callback');

    $this->assertGuest();
    expect(User::where('email', 'invited@example.test')->exists())->toBeFalse();
});

test('Microsoft with xms_edov links to an existing user', function () {
    $user = User::factory()->create(['email' => 'known@example.test']);
    fakeProviderUser('microsoft', 'known@example.test');

    $this->get('/auth/microsoft/callback');

    $this->assertAuthenticatedAs($user);
});

test('an already linked Microsoft account logs in without xms_edov', function () {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'microsoft', 'provider_user_id' => 'provider-1']);
    fakeProviderUser('microsoft', 'anything@example.test', 'provider-1', verified: false);

    $this->get('/auth/microsoft/callback');

    $this->assertAuthenticatedAs($user);
});

test('an invited user created by SSO joins the invited team and can use the app', function () {
    $team = Team::factory()->tenant()->create(['name' => 'Invited Tenant']);
    $team->teamInvitations()->create(['email' => 'invited@example.test', 'role' => 'editor']);
    fakeProviderUser('google', 'invited@example.test');

    $this->get('/auth/google/callback')->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'invited@example.test')->firstOrFail();
    expect($user->current_team_id)->toBe($team->id)
        ->and($team->fresh()->users()->whereKey($user->id)->first()->membership->role)->toBe('editor')
        ->and($team->teamInvitations()->count())->toBe(0);

    $this->get('/dashboard')->assertOk();
});

test('all active invitations for the address are accepted, expired ones are not', function () {
    $a = Team::factory()->tenant()->create();
    $b = Team::factory()->tenant()->create();
    $old = Team::factory()->tenant()->create();
    $a->teamInvitations()->create(['email' => 'invited@example.test', 'role' => 'reader']);
    $b->teamInvitations()->create(['email' => 'invited@example.test', 'role' => 'editor']);
    $old->teamInvitations()->create(['email' => 'invited@example.test', 'role' => 'reader'])->forceFill(['created_at' => now()->subDays(8)])->save();
    fakeProviderUser('google', 'invited@example.test');

    $this->get('/auth/google/callback');

    $user = User::where('email', 'invited@example.test')->firstOrFail();
    expect($user->current_team_id)->toBe($a->id)
        ->and($user->teams()->pluck('teams.id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

test('a mixed-case invitation is accepted after SSO', function () {
    $team = Team::factory()->tenant()->create();
    // Created directly with mixed case, as an older invitation might be.
    $team->teamInvitations()->create(['email' => 'Mixed.Case@Example.test', 'role' => 'reader']);
    fakeProviderUser('google', 'mixed.case@example.test');

    $this->get('/auth/google/callback');

    $user = User::where('email', 'mixed.case@example.test')->firstOrFail();
    expect($team->fresh()->hasUserWithEmail('mixed.case@example.test'))->toBeTrue()
        ->and($user->current_team_id)->toBe($team->id);
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
    foreach (range(1, 10) as $_) {
        $this->get('/auth/google/redirect')->assertRedirect();
    }

    $this->get('/auth/google/redirect')->assertStatus(429);
});

test('Microsoft links and creates with the verified email claim, never with the UPN', function () {
    // The UPN (getEmail) belongs to a victim, the verified claim to the invited person.
    $victim = User::factory()->create(['email' => 'victim@example.test']);
    invite('invited@example.test');
    fakeProviderUser('microsoft', 'victim@example.test', claimEmail: 'invited@example.test');

    $this->get('/auth/microsoft/callback');

    $this->assertAuthenticated();
    expect(auth()->user()->email)->toBe('invited@example.test')
        ->and(auth()->user()->isNot($victim))->toBeTrue()
        ->and($victim->socialAccounts()->count())->toBe(0);
});

test('Microsoft whose UPN matches a victim but whose claim matches nobody gets nothing', function () {
    $victim = User::factory()->create(['email' => 'victim@example.test']);
    fakeProviderUser('microsoft', 'victim@example.test', claimEmail: 'attacker@evil.test');

    $this->get('/auth/microsoft/callback');

    $this->assertGuest();
    expect(SocialAccount::count())->toBe(0)
        ->and($victim->socialAccounts()->count())->toBe(0);
});

test('an invitation that cannot be accepted gives no user: closed tenant', function () {
    $closed = Team::factory()->tenant()->create(['status' => 'closed']);
    $closed->teamInvitations()->create(['email' => 'invited@example.test', 'role' => 'reader']);
    fakeProviderUser('google', 'invited@example.test');
    $users = User::count();

    $this->get('/auth/google/callback')->assertRedirect(route('login'));

    $this->assertGuest();
    expect(User::count())->toBe($users)
        ->and(SocialAccount::count())->toBe(0)
        ->and($closed->teamInvitations()->count())->toBe(1);
});

test('an invitation that cannot be accepted gives no user: partner role in a client tenant', function () {
    $partner = Team::factory()->tenant()->create();
    $client = Team::factory()->clientOf($partner)->create();
    $client->teamInvitations()->create(['email' => 'invited@example.test', 'role' => 'partner_advisor']);
    fakeProviderUser('google', 'invited@example.test');
    $users = User::count();

    $this->get('/auth/google/callback')->assertRedirect(route('login'));

    $this->assertGuest();
    expect(User::count())->toBe($users)
        ->and(SocialAccount::count())->toBe(0);
});

test('the invitation link is not the intended URL after SSO accepted the invitation', function () {
    $team = Team::factory()->tenant()->create();
    $invitation = $team->teamInvitations()->create(['email' => 'invited@example.test', 'role' => 'reader']);
    fakeProviderUser('google', 'invited@example.test');

    $this->withSession(['url.intended' => "http://localhost/team-invitations/{$invitation->id}"])
        ->get('/auth/google/callback')->assertRedirect(route('dashboard', absolute: false));
});
