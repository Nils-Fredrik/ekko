<?php

use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/_test/tenant', fn () => ['tenant' => app(TenantContext::class)->id()]);
});

test('a signed-in user works in their current tenant', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $tenant = Team::factory()->tenant()->create();
    $tenant->users()->attach($user, ['role' => 'editor']);
    $user->switchTeam($tenant);

    $this->actingAs($user)->get('/_test/tenant')->assertExactJson(['tenant' => $tenant->id]);
});

test('a guest has no tenant', function () {
    $this->get('/_test/tenant')->assertExactJson(['tenant' => null]);
});

test('a user who no longer belongs to the current tenant gets no tenant', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $tenant = Team::factory()->tenant()->create();
    $user->forceFill(['current_team_id' => $tenant->id])->save();

    $this->actingAs($user)->get('/_test/tenant')->assertExactJson(['tenant' => null]);
});

test('the tenant of one request never carries over to the next', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $tenant = Team::factory()->tenant()->create();
    $tenant->users()->attach($user, ['role' => 'editor']);
    $user->switchTeam($tenant);

    $this->actingAs($user)->get('/_test/tenant')->assertExactJson(['tenant' => $tenant->id]);

    auth()->logout();
    $this->get('/_test/tenant')->assertExactJson(['tenant' => null]);
});
