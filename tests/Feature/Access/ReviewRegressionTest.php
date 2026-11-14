<?php

use App\Enums\TenantPermission;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\ActingAs;
use App\Tenancy\TenantAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

// Regression tests for the review of PR #7.

test('an invitation as partner advisor, sent by a partner advisor, can be accepted', function () {
    Mail::fake();
    $partner = Team::factory()->tenant()->create();
    $advisor = User::factory()->create();
    $partner->users()->attach($advisor, ['role' => 'partner_advisor']);
    $invitee = User::factory()->withPersonalTeam()->create();

    $this->actingAs($advisor)
        ->post("/teams/{$partner->id}/members", ['email' => $invitee->email, 'role' => 'partner_advisor'])
        ->assertRedirect();

    $invitation = $partner->teamInvitations()->sole();

    $this->actingAs($invitee)
        ->get(URL::signedRoute('team-invitations.accept', ['invitation' => $invitation->id]))
        ->assertRedirect();

    expect($partner->users()->whereKey($invitee->id)->first()?->membership->role)->toBe('partner_advisor')
        ->and($partner->teamInvitations()->count())->toBe(0);
});

test('a user without a personal team and without a current tenant can open the dashboard', function () {
    $tenant = Team::factory()->tenant()->create();
    $user = User::factory()->create();
    $tenant->users()->attach($user, ['role' => 'reader']);

    expect($user->personalTeam())->toBeNull()
        ->and($user->current_team_id)->toBeNull();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

test('switchTeam refuses null', function () {
    expect(User::factory()->create()->switchTeam(null))->toBeFalse();
});

test('the switcher hides clients of a closed partner, so it never lists a tenant that cannot be opened', function () {
    $partner = Team::factory()->tenant()->create(['name' => 'Partner']);
    $client = Team::factory()->clientOf($partner)->create(['name' => 'Client']);
    $advisor = User::factory()->create();
    $partner->users()->attach($advisor, ['role' => 'partner_advisor']);

    $partner->forceFill(['status' => 'closed'])->save();

    expect($advisor->fresh()->allTeams()->pluck('name')->all())->toBe([]);

    $this->actingAs($advisor)->put('/current-team', ['team_id' => $client->id])->assertForbidden();
});

test('a partner advisor stays tenant admin in a client, also with a lower membership role there', function (string $role) {
    $partner = Team::factory()->tenant()->create();
    $client = Team::factory()->clientOf($partner)->create();
    $advisor = User::factory()->create();
    $partner->users()->attach($advisor, ['role' => 'partner_advisor']);
    $client->users()->attach($advisor, ['role' => $role]);

    foreach (TenantPermission::cases() as $permission) {
        expect(app(TenantAccess::class)->can($advisor, $client, $permission))->toBeTrue($permission->value);
    }
})->with(['reader', 'editor']);

test('a membership role stronger than nothing is kept when there is no partner role', function () {
    $tenant = Team::factory()->tenant()->create();
    $editor = User::factory()->create();
    $tenant->users()->attach($editor, ['role' => 'editor']);

    expect(app(TenantAccess::class)->can($editor, $tenant, TenantPermission::ManageMembers))->toBeFalse()
        ->and(app(TenantAccess::class)->can($editor, $tenant, TenantPermission::ManagePrompts))->toBeTrue();
});

test('while acting as a tenant, the navigation shows that tenant and not the stored current tenant', function () {
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->create();
    $own = $admin->personalTeam();
    $admin->switchTeam($own);
    $target = Team::factory()->tenant()->create(['name' => 'Customer AS']);

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post("/admin/acting-as/{$target->id}", ['reason' => 'Support case 4711']);

    $this->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.user.current_team.id', $target->id)
        ->where('auth.user.current_team.name', 'Customer AS'));

    expect($admin->fresh()->current_team_id)->toBe($own->id);
});

test('the partner advisor role cannot be given in a client tenant, even when acting as it', function () {
    $partner = Team::factory()->tenant()->create();
    $client = Team::factory()->clientOf($partner)->create();
    $platformAdmin = User::factory()->platformAdmin()->create();
    app(ActingAs::class)->start($platformAdmin, $client, 'Set up client');

    expect(Gate::forUser($platformAdmin)->allows('assignRole', [$client, 'partner_advisor']))->toBeFalse()
        ->and(Gate::forUser($platformAdmin)->allows('assignRole', [$client, 'admin']))->toBeTrue();
});

test('a tenant owner cannot delete the tenant by deleting their account', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $owner->ownedTeams()->save($tenant = Team::factory()->make(['personal_team' => false]));

    $this->actingAs($owner)
        ->delete('/user', ['password' => 'password'])
        ->assertSessionHasErrors(['password' => __('app.account.owns_tenants')]);

    expect($owner->fresh())->not->toBeNull()
        ->and($tenant->fresh())->not->toBeNull();
});

test('a user who owns only a personal team can still delete the account', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->delete('/user', ['password' => 'password']);

    expect($user->fresh())->toBeNull();
});

test('an old partner advisor invitation cannot be accepted after the tenant became a client', function () {
    Mail::fake();
    $tenant = Team::factory()->tenant()->create();
    $advisor = User::factory()->create();
    $tenant->users()->attach($advisor, ['role' => 'partner_advisor']);
    $invitee = User::factory()->withPersonalTeam()->create();

    $this->actingAs($advisor)->post("/teams/{$tenant->id}/members", ['email' => $invitee->email, 'role' => 'partner_advisor']);
    $invitation = $tenant->teamInvitations()->sole();

    $partner = Team::factory()->tenant()->create();
    $tenant->forceFill(['partner_team_id' => $partner->id])->save();

    $this->actingAs($invitee)
        ->get(URL::signedRoute('team-invitations.accept', ['invitation' => $invitation->id]))
        ->assertForbidden();

    expect($tenant->hasUserWithEmail($invitee->email))->toBeFalse();
});

test('the switcher uses a fixed number of queries, however many client tenants there are', function () {
    $queriesFor = function (int $clients): int {
        $partner = Team::factory()->tenant()->create();
        Team::factory()->count($clients)->clientOf($partner)->create();
        $advisor = User::factory()->create();
        $partner->users()->attach($advisor, ['role' => 'partner_advisor']);
        $advisor = $advisor->fresh();
        app(TenantAccess::class)->flush();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $teams = $advisor->allTeams();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($teams)->toHaveCount($clients + 1);

        return $count;
    };

    expect($queriesFor(10))->toBe($queriesFor(1));
});
