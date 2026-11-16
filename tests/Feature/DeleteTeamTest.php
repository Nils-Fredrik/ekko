<?php

use App\Enums\TenantStatus;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantAccess;

// Only the platform admin closes and deletes tenants (docs/spec.md 5.2). "Delete" closes the tenant, with a
// confirmed password; the tenant and its data are deleted 30 days later (docs/spec.md 16, T39-F6).

test('a platform admin closes the tenant, and the members lose access at once', function () {
    $this->actingAs(User::factory()->platformAdmin()->withPersonalTeam()->create());

    $team = Team::factory()->tenant()->create();
    $team->users()->attach($otherUser = User::factory()->create(), ['role' => 'editor']);

    $this->withSession(['auth.password_confirmed_at' => time()])->delete('/teams/'.$team->id)->assertSessionHasNoErrors();

    $team->refresh();
    expect($team->status)->toBe(TenantStatus::Closed)
        ->and($team->closed_at)->not->toBeNull()
        ->and($otherUser->fresh()->teams)->toHaveCount(1);

    expect(app(TenantAccess::class)->canSelect($otherUser->fresh(), $team))->toBeFalse();
});

test('closing a tenant needs a recently confirmed password', function () {
    $this->actingAs(User::factory()->platformAdmin()->withPersonalTeam()->create());
    $team = Team::factory()->tenant()->create();

    $this->delete('/teams/'.$team->id)->assertSessionHasErrorsIn('deleteTeam', 'password');

    expect($team->fresh()->status)->toBe(TenantStatus::Active)
        ->and($team->fresh()->closed_at)->toBeNull();

    $this->withSession(['auth.password_confirmed_at' => time() - 4 * 3600])->delete('/teams/'.$team->id)
        ->assertSessionHasErrorsIn('deleteTeam', 'password');
    expect($team->fresh()->status)->toBe(TenantStatus::Active);
});

test('closing again does not move the deletion date', function () {
    $this->actingAs(User::factory()->platformAdmin()->withPersonalTeam()->create());
    $team = Team::factory()->tenant()->create();
    $team->forceFill(['status' => TenantStatus::Closed, 'closed_at' => now()->subDays(10)])->save();
    $closedAt = $team->fresh()->closed_at;

    $this->withSession(['auth.password_confirmed_at' => time()])->delete('/teams/'.$team->id);

    expect($team->fresh()->closed_at->equalTo($closedAt))->toBeTrue();
});

test('a tenant owner cannot delete the tenant', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $user->ownedTeams()->save($team = Team::factory()->make(['personal_team' => false]));

    $this->withSession(['auth.password_confirmed_at' => time()])->delete('/teams/'.$team->id)->assertForbidden();

    expect($team->fresh()->status)->not->toBe(TenantStatus::Closed);
});

test('personal teams cant be deleted', function () {
    $this->actingAs($user = User::factory()->platformAdmin()->withPersonalTeam()->create());

    $this->withSession(['auth.password_confirmed_at' => time()])->delete('/teams/'.$user->currentTeam->id);

    expect($user->currentTeam->fresh())->not->toBeNull()
        ->and($user->currentTeam->fresh()->status)->not->toBe(TenantStatus::Closed);
});
