<?php

use App\Models\User;

// Tenants are created by the platform admin only; there is no self-service (docs/spec.md 1 and 5.2).

test('a platform admin can create tenants', function () {
    $this->actingAs($user = User::factory()->platformAdmin()->withPersonalTeam()->create());

    $this->post('/teams', ['name' => 'Test Team']);

    expect($user->fresh()->ownedTeams)->toHaveCount(2);
    expect($user->fresh()->ownedTeams()->latest('id')->first()->name)->toEqual('Test Team');
});

test('other users cannot create tenants', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $this->post('/teams', ['name' => 'Test Team'])->assertForbidden();

    expect($user->fresh()->ownedTeams)->toHaveCount(1);
});
