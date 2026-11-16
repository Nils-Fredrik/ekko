<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;

// docs/spec.md 5.4 and T07: invitations expire after 7 days, enforced server-side. Finding #140:
// the invitation also needs a verified e-mail address.

function invitationFor(User $user, ?DateTimeInterface $createdAt = null): TeamInvitation
{
    $team = Team::factory()->tenant()->create();
    $invitation = $team->teamInvitations()->create(['email' => $user->email, 'role' => 'reader']);

    if ($createdAt !== null) {
        $invitation->forceFill(['created_at' => $createdAt])->save();
    }

    return $invitation;
}

function acceptUrl(TeamInvitation $invitation): string
{
    return URL::signedRoute('team-invitations.accept', ['invitation' => $invitation->id]);
}

test('an invitation can be accepted within 7 days', function () {
    $user = User::factory()->create();
    $invitation = invitationFor($user, now()->subDays(6)->subHours(23));

    $this->actingAs($user)->get(acceptUrl($invitation))->assertRedirect(config('fortify.home'));

    expect($invitation->team->fresh()->hasUser($user))->toBeTrue()
        ->and(TeamInvitation::count())->toBe(0);
});

test('an invitation older than 7 days is refused and removed', function () {
    $user = User::factory()->create();
    $invitation = invitationFor($user, now()->subDays(7)->subMinute());

    $this->actingAs($user)->get(acceptUrl($invitation))
        ->assertRedirect(config('fortify.home'))
        ->assertSessionHas('flash.bannerStyle', 'danger');

    expect($invitation->team->fresh()->hasUser($user))->toBeFalse()
        ->and(TeamInvitation::count())->toBe(0);
});

test('the expiry follows the configured number of days', function () {
    config(['ekko.invitation_ttl_days' => 1]);
    $user = User::factory()->create();
    $invitation = invitationFor($user, now()->subDays(2));

    $this->actingAs($user)->get(acceptUrl($invitation));

    expect($invitation->team->fresh()->hasUser($user))->toBeFalse();
});

test('an expired invitation does not block a new invitation to the same person', function () {
    Mail::fake();
    $admin = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->tenant()->create(['user_id' => $admin->id]);
    $admin->switchTeam($team);
    $old = $team->teamInvitations()->create(['email' => 'again@example.test', 'role' => 'reader']);
    $old->forceFill(['created_at' => now()->subDays(8)])->save();

    $this->actingAs($admin)->post("/teams/{$team->id}/members", ['email' => 'again@example.test', 'role' => 'reader'])
        ->assertSessionHasNoErrors();

    Mail::assertSent(TeamInvitationMail::class);
    expect($team->teamInvitations()->count())->toBe(1)
        ->and($team->teamInvitations()->first()->isExpired())->toBeFalse();
});

test('an unexpired invitation still blocks a duplicate', function () {
    Mail::fake();
    $admin = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->tenant()->create(['user_id' => $admin->id]);
    $admin->switchTeam($team);
    $team->teamInvitations()->create(['email' => 'again@example.test', 'role' => 'reader']);

    $this->actingAs($admin)->post("/teams/{$team->id}/members", ['email' => 'again@example.test', 'role' => 'reader'])
        ->assertSessionHasErrorsIn('addTeamMember', 'email');
});

test('finding 140: a user who changed e-mail to the invited address cannot accept with a leaked link', function () {
    $attacker = User::factory()->create(['email' => 'attacker@example.test']);
    $invitation = Team::factory()->tenant()->create()->teamInvitations()->create(['email' => 'victim@example.test', 'role' => 'admin']);
    $link = acceptUrl($invitation);

    // Changing the address through the profile clears the verification.
    $this->actingAs($attacker)->put('/user/profile-information', ['name' => $attacker->name, 'email' => 'victim@example.test', 'current_password' => 'password']);

    expect($attacker->fresh()->email)->toBe('victim@example.test')
        ->and($attacker->fresh()->hasVerifiedEmail())->toBeFalse();

    // Jetstream's accept route has the verified middleware, which now applies (User is MustVerifyEmail).
    $this->actingAs($attacker->fresh())->get($link)->assertRedirect(route('verification.notice'));

    expect($invitation->team->fresh()->hasUser($attacker->fresh()))->toBeFalse()
        ->and(TeamInvitation::count())->toBe(1);
});

test('an unverified user with a matching e-mail address cannot accept', function () {
    $user = User::factory()->unverified()->create();
    $invitation = invitationFor($user);

    $this->actingAs($user)->get(acceptUrl($invitation))->assertRedirect(route('verification.notice'));

    expect($invitation->team->fresh()->hasUser($user))->toBeFalse();
});

test('a user with an unverified e-mail address is held at the verification page', function () {
    $this->actingAs(User::factory()->unverified()->create())->get('/dashboard')->assertRedirect(route('verification.notice'));
});

test('a verified user who changes e-mail address gets a verification mail and loses the verification', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->put('/user/profile-information', ['name' => $user->name, 'email' => 'new@example.test', 'current_password' => 'password']);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user->fresh(), VerifyEmail::class);
});

test('the controller itself refuses an unverified user, whatever the route middleware does', function () {
    $user = User::factory()->unverified()->create();
    $invitation = invitationFor($user);

    $this->withoutMiddleware(EnsureEmailIsVerified::class)
        ->actingAs($user)->get(acceptUrl($invitation))->assertForbidden();

    expect($invitation->team->fresh()->hasUser($user))->toBeFalse();
});

test('an invitation sent with mixed case is stored lower case and accepted by the user with the lower case address', function () {
    Mail::fake();
    $admin = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->tenant()->create(['user_id' => $admin->id, 'name' => 'Case Tenant']);
    $admin->switchTeam($team);
    $invitee = User::factory()->create(['email' => 'ann@example.test']);

    $this->actingAs($admin)->post("/teams/{$team->id}/members", ['email' => 'Ann@Example.TEST', 'role' => 'reader'])
        ->assertSessionHasNoErrors();

    $invitation = $team->teamInvitations()->firstOrFail();
    expect($invitation->email)->toBe('ann@example.test');

    // The invitee, signed in with the lower case address.
    $this->actingAs($invitee)->get(acceptUrl($invitation))->assertRedirect(config('fortify.home'));

    expect($team->fresh()->hasUser($invitee))->toBeTrue()
        ->and($invitee->fresh()->current_team_id)->toBe($team->id);
});

test('an old invitation with mixed case is accepted by the user with the lower case address', function () {
    $user = User::factory()->create(['email' => 'ann@example.test']);
    $team = Team::factory()->tenant()->create();
    $invitation = $team->teamInvitations()->create(['email' => 'Ann@Example.test', 'role' => 'reader']);

    $this->actingAs($user)->get(acceptUrl($invitation))->assertRedirect(config('fortify.home'));

    expect($team->fresh()->hasUser($user))->toBeTrue();
});

test('accepting switches to the team only when the user has no current team', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $mine = Team::factory()->tenant()->create(['user_id' => $user->id]);
    $user->switchTeam($mine);
    $own = $mine->id;
    $invitation = invitationFor($user);

    $this->actingAs($user)->get(acceptUrl($invitation));

    expect($user->fresh()->current_team_id)->toBe($own);
});

test('an e-mail address is stored lower case', function () {
    $user = User::factory()->create(['email' => '  Mixed@Example.TEST ']);

    expect($user->fresh()->email)->toBe('mixed@example.test');
});

test('changing the profile e-mail to a different-case variant of another address is refused', function () {
    User::factory()->create(['email' => 'victim@example.test']);
    $attacker = User::factory()->create(['email' => 'attacker@example.test']);

    $this->actingAs($attacker)->put('/user/profile-information', ['name' => $attacker->name, 'email' => 'Victim@Example.test', 'current_password' => 'password'])
        ->assertSessionHasErrorsIn('updateProfileInformation', 'email');

    expect($attacker->fresh()->email)->toBe('attacker@example.test')
        ->and(User::whereRaw('lower(email) = ?', ['victim@example.test'])->count())->toBe(1);
});

test('the database refuses two addresses that differ only in case', function () {
    User::factory()->create(['email' => 'one@example.test']);

    expect(fn () => DB::table('users')->insert([
        'name' => 'Dup', 'email' => 'One@Example.test', 'password' => 'x',
    ]))->toThrow(QueryException::class);
});

test('the invitation for a mixed-case address cannot land on a look-alike user', function () {
    $victim = User::factory()->create(['email' => 'victim@example.test']);
    $attacker = User::factory()->create(['email' => 'attacker@example.test']);
    $this->actingAs($attacker)->put('/user/profile-information', ['name' => 'x', 'email' => 'Victim@example.test', 'current_password' => 'password']);

    $team = Team::factory()->tenant()->create();
    $invitation = $team->teamInvitations()->create(['email' => 'Victim@example.test', 'role' => 'admin']);

    $this->actingAs($victim)->get(acceptUrl($invitation));

    expect($team->fresh()->hasUser($victim))->toBeTrue()
        ->and($team->fresh()->hasUser($attacker->fresh()))->toBeFalse();
});

test('the e-mail migration stops on case duplicates and names user ids, not addresses', function () {
    DB::statement('DROP INDEX users_email_lower_unique');
    $first = User::factory()->create(['email' => 'dup@example.test']);
    DB::table('users')->insert(['name' => 'Dup', 'email' => 'Dup@Example.test', 'password' => 'x']);
    $second = DB::table('users')->where('email', 'Dup@Example.test')->value('id');

    $migration = require database_path('migrations/2026_11_14_190000_make_user_email_case_insensitive_unique.php');

    try {
        $migration->up();
        $this->fail('The migration should refuse case duplicates.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain("{$first->id}+{$second}")
            ->and(strtolower($e->getMessage()))->not->toContain('dup@example.test');
    }

    expect(DB::table('users')->where('email', 'Dup@Example.test')->exists())->toBeTrue();
});
