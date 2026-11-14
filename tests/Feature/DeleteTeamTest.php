<?php

use App\Models\Team;
use App\Models\User;

// Only the platform admin closes and deletes tenants (docs/spec.md 5.2).

test('a platform admin can delete tenants', function () {
    $this->actingAs(User::factory()->platformAdmin()->withPersonalTeam()->create());

    $team = Team::factory()->tenant()->create();
    $team->users()->attach($otherUser = User::factory()->create(), ['role' => 'editor']);

    $this->delete('/teams/'.$team->id);

    expect($team->fresh())->toBeNull();
    expect($otherUser->fresh()->teams)->toHaveCount(0);
});

test('a tenant owner cannot delete the tenant', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $user->ownedTeams()->save($team = Team::factory()->make(['personal_team' => false]));

    $this->delete('/teams/'.$team->id)->assertForbidden();

    expect($team->fresh())->not->toBeNull();
});

test('personal teams cant be deleted', function () {
    $this->actingAs($user = User::factory()->platformAdmin()->withPersonalTeam()->create());

    $this->delete('/teams/'.$user->currentTeam->id);

    expect($user->currentTeam->fresh())->not->toBeNull();
});
