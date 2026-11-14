<?php

use App\Models\User;

// Tenant admins invite and remove users (docs/spec.md 5.2).

test('team members can be removed from teams', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    $user->currentTeam->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    $this->delete('/teams/'.$user->currentTeam->id.'/members/'.$otherUser->id);

    expect($user->currentTeam->fresh()->users)->toHaveCount(0);
});

test('a tenant admin can remove other members', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $owner->currentTeam->users()->attach($admin = User::factory()->create(), ['role' => 'admin']);
    $owner->currentTeam->users()->attach($reader = User::factory()->create(), ['role' => 'reader']);

    $this->actingAs($admin)->delete('/teams/'.$owner->currentTeam->id.'/members/'.$reader->id);

    expect($owner->currentTeam->fresh()->users->pluck('id')->all())->toBe([$admin->id]);
});

test('editors and readers cannot remove members', function (string $role) {
    $owner = User::factory()->withPersonalTeam()->create();
    $owner->currentTeam->users()->attach($member = User::factory()->create(), ['role' => $role]);
    $owner->currentTeam->users()->attach($other = User::factory()->create(), ['role' => 'reader']);

    $this->actingAs($member)
        ->delete('/teams/'.$owner->currentTeam->id.'/members/'.$other->id)
        ->assertForbidden();

    expect($owner->currentTeam->fresh()->users)->toHaveCount(2);
})->with(['editor', 'reader']);

test('the owner cannot be removed', function () {
    $owner = User::factory()->withPersonalTeam()->create();
    $owner->currentTeam->users()->attach($admin = User::factory()->create(), ['role' => 'admin']);

    $this->actingAs($admin)->delete('/teams/'.$owner->currentTeam->id.'/members/'.$owner->id);

    expect($owner->currentTeam->fresh()->owner->is($owner))->toBeTrue();
});
