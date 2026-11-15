<?php

use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\TenantRecord;

// docs/spec.md 5.4: users with several tenants switch in the top menu; the choice is stored on the user.

beforeEach(function () {
    Route::middleware('web')->get('/_test/context', fn () => ['tenant' => app(TenantContext::class)->id()]);

    $this->partner = Team::factory()->tenant()->create(['name' => 'Partner']);
    $this->client = Team::factory()->clientOf($this->partner)->create(['name' => 'Client']);
    $this->unrelated = Team::factory()->tenant()->create(['name' => 'Unrelated']);

    $this->advisor = User::factory()->withTwoFactor()->create();
    $this->partner->users()->attach($this->advisor, ['role' => 'partner_advisor']);
});

test('the switcher lists memberships and partner clients, not other tenants', function () {
    expect($this->advisor->allTeams()->pluck('name')->all())->toBe(['Client', 'Partner']);
});

test('the switcher hides closed tenants', function () {
    $this->client->forceFill(['status' => 'closed'])->save();

    expect($this->advisor->fresh()->allTeams()->pluck('name')->all())->toBe(['Partner']);
});

test('a partner advisor can switch to a client tenant, and the choice is stored on the user', function () {
    $this->actingAs($this->advisor)
        ->put('/current-team', ['team_id' => $this->client->id])
        ->assertRedirect();

    expect($this->advisor->fresh()->current_team_id)->toBe($this->client->id);

    $this->actingAs($this->advisor->fresh())->get('/_test/context')->assertExactJson(['tenant' => $this->client->id]);
});

test('switching to a tenant without access is refused', function () {
    $this->actingAs($this->advisor)
        ->put('/current-team', ['team_id' => $this->unrelated->id])
        ->assertForbidden();

    expect($this->advisor->fresh()->current_team_id)->not->toBe($this->unrelated->id);
});

test('a stored current tenant without access gives no tenant context', function () {
    $this->advisor->forceFill(['current_team_id' => $this->unrelated->id])->save();

    $this->actingAs($this->advisor)->get('/_test/context')->assertExactJson(['tenant' => null]);
});

test('a partner advisor loses client access when the role changes', function () {
    $this->partner->users()->updateExistingPivot($this->advisor->id, ['role' => 'reader']);
    $this->advisor->forceFill(['current_team_id' => $this->client->id])->save();

    $this->actingAs($this->advisor->fresh())->get('/_test/context')->assertExactJson(['tenant' => null]);
});

test('row-level security for a partner advisor in the partner tenant covers its clients', function () {
    $access = app(TenantAccess::class);
    $closed = Team::factory()->clientOf($this->partner)->create(['status' => 'closed']);

    expect($access->rlsTenantIds($this->advisor, $this->partner))->toBe([$this->partner->id, $this->client->id])
        ->and($access->rlsTenantIds($this->advisor, $this->client))->toBe([$this->client->id])
        ->and($access->rlsTenantIds($this->advisor, $this->unrelated))->toBe([])
        ->and($access->rlsTenantIds($this->advisor, $closed))->toBe([]);
});

test('row-level security for other roles in the partner tenant covers only the partner tenant', function () {
    $admin = User::factory()->create();
    $this->partner->users()->attach($admin, ['role' => 'admin']);

    expect(app(TenantAccess::class)->rlsTenantIds($admin, $this->partner))->toBe([$this->partner->id]);
});

test('a partner advisor request in the partner tenant sees client rows under RLS', function () {
    TenantRecord::createTable();
    foreach ([$this->partner, $this->client, $this->unrelated] as $tenant) {
        DB::table('test_tenant_records')->insert([
            'tenant_id' => $tenant->id, 'name' => $tenant->name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    Route::middleware('web')->get('/_test/rls-names', fn () => DB::table('test_tenant_records')->orderBy('name')->pluck('name'));

    $this->advisor->forceFill(['current_team_id' => $this->partner->id])->save();

    $this->actingAs($this->advisor)->get('/_test/rls-names')->assertExactJson(['Client', 'Partner']);
});
