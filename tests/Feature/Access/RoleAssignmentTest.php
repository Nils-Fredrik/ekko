<?php

use App\Models\Team;
use App\Models\User;
use App\Tenancy\ActingAs;
use Illuminate\Support\Facades\Gate;

// The partner advisor role reaches client tenants, so only a partner advisor (or a platform admin
// acting as the tenant) may give it. Other roles follow ManageMembers.

beforeEach(function () {
    $this->tenant = Team::factory()->tenant()->create();
    $this->target = User::factory()->create();
    $this->tenant->users()->attach($this->target, ['role' => 'reader']);
});

function roleOf(User $user, Team $team): ?string
{
    return $team->users()->whereKey($user->id)->first()?->membership->role;
}

test('a tenant admin can give the admin, editor and reader roles', function (string $role) {
    $admin = User::factory()->create();
    $this->tenant->users()->attach($admin, ['role' => 'admin']);

    $this->actingAs($admin)->put("/teams/{$this->tenant->id}/members/{$this->target->id}", ['role' => $role]);

    expect(roleOf($this->target, $this->tenant))->toBe($role);
})->with(['admin', 'editor', 'reader']);

test('a tenant admin cannot give the partner advisor role', function () {
    $admin = User::factory()->create();
    $this->tenant->users()->attach($admin, ['role' => 'admin']);

    $this->actingAs($admin)
        ->put("/teams/{$this->tenant->id}/members/{$this->target->id}", ['role' => 'partner_advisor'])
        ->assertForbidden();

    expect(roleOf($this->target, $this->tenant))->toBe('reader');
});

test('a tenant admin cannot add or invite someone as partner advisor', function () {
    $admin = User::factory()->create();
    $this->tenant->users()->attach($admin, ['role' => 'admin']);
    $newcomer = User::factory()->create();

    $this->actingAs($admin)
        ->post("/teams/{$this->tenant->id}/members", ['email' => $newcomer->email, 'role' => 'partner_advisor'])
        ->assertForbidden();

    expect($this->tenant->fresh()->hasUserWithEmail($newcomer->email))->toBeFalse()
        ->and($this->tenant->teamInvitations()->count())->toBe(0);
});

test('a partner advisor can give the partner advisor role', function () {
    $advisor = User::factory()->withTwoFactor()->create();
    $this->tenant->users()->attach($advisor, ['role' => 'partner_advisor']);

    $this->actingAs($advisor)->put("/teams/{$this->tenant->id}/members/{$this->target->id}", ['role' => 'partner_advisor']);

    expect(roleOf($this->target, $this->tenant))->toBe('partner_advisor');
});

test('a platform admin acting as the tenant can give the partner advisor role', function () {
    $platformAdmin = User::factory()->platformAdmin()->create();
    app(ActingAs::class)->start($platformAdmin, $this->tenant, 'Set up partner');

    expect(Gate::forUser($platformAdmin)->allows('assignRole', [$this->tenant, 'partner_advisor']))->toBeTrue();
});

test('unknown roles are refused', function () {
    $admin = User::factory()->create();
    $this->tenant->users()->attach($admin, ['role' => 'admin']);

    expect(Gate::forUser($admin)->allows('assignRole', [$this->tenant, 'owner']))->toBeFalse();
});
