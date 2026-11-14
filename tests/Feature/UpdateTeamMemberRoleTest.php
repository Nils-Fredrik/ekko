<?php

use App\Models\User;

// Tenant admins change roles (docs/spec.md 5.2).

test('team member roles can be updated', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $user->currentTeam->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    $this->put('/teams/'.$user->currentTeam->id.'/members/'.$otherUser->id, [
        'role' => 'editor',
    ]);

    expect($otherUser->fresh()->hasTeamRole(
        $user->currentTeam->fresh(), 'editor'
    ))->toBeTrue();
});

test('editors and readers cannot update member roles', function (string $role) {
    $user = User::factory()->withPersonalTeam()->create();

    $user->currentTeam->users()->attach(
        $otherUser = User::factory()->create(), ['role' => $role]
    );

    $this->actingAs($otherUser);

    $this->put('/teams/'.$user->currentTeam->id.'/members/'.$otherUser->id, [
        'role' => 'admin',
    ])->assertForbidden();

    expect($otherUser->fresh()->hasTeamRole(
        $user->currentTeam->fresh(), $role
    ))->toBeTrue();
})->with(['editor', 'reader']);
