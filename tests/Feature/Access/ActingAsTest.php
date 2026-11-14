<?php

use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// docs/spec.md 5.2 and 16: a platform admin can see a tenant as it sees itself. The action needs
// a reason, is logged, and is shown as a banner.

beforeEach(function () {
    Route::middleware('web')->get('/_test/context', fn () => ['tenant' => app(TenantContext::class)->id()]);

    $this->tenant = Team::factory()->tenant()->create(['name' => 'Customer AS']);
    $this->admin = User::factory()->platformAdmin()->withPersonalTeam()->create();
});

function confirmedPassword(): array
{
    return ['auth.password_confirmed_at' => time()];
}

test('starting needs a recently confirmed password', function () {
    $this->actingAs($this->admin)
        ->post("/admin/acting-as/{$this->tenant->id}", ['reason' => 'Support case 4711'])
        ->assertRedirect(route('password.confirm'));
});

test('starting needs a reason', function (string $reason) {
    $this->actingAs($this->admin)->withSession(confirmedPassword())
        ->post("/admin/acting-as/{$this->tenant->id}", ['reason' => $reason])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->admin)->get('/_test/context')->assertExactJson(['tenant' => $this->admin->fresh()->current_team_id]);
})->with(['', 'too short']);

test('only platform admins can act as a tenant', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->withSession(confirmedPassword())
        ->post("/admin/acting-as/{$this->tenant->id}", ['reason' => 'Support case 4711'])
        ->assertForbidden();
});

test('acting as a tenant sets the tenant context, shows the banner and is logged', function () {
    Log::spy();

    $this->actingAs($this->admin)->withSession(confirmedPassword())
        ->post("/admin/acting-as/{$this->tenant->id}", ['reason' => 'Support case 4711'])
        ->assertRedirect(route('dashboard'));

    $this->get('/_test/context')->assertExactJson(['tenant' => $this->tenant->id]);

    $this->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('actingAs.tenant', 'Customer AS')
        ->where('actingAs.reason', 'Support case 4711'));

    Log::shouldHaveReceived('info')->with('acting_as.started', Mockery::on(fn ($context) => $context['user_id'] === $this->admin->id
        && $context['tenant_id'] === $this->tenant->id
        && $context['reason'] === 'Support case 4711'));

    expect($this->admin->fresh()->current_team_id)->not->toBe($this->tenant->id);
});

test('stopping ends the context and the banner, and is logged', function () {
    Log::spy();

    $this->actingAs($this->admin)->withSession(confirmedPassword())
        ->post("/admin/acting-as/{$this->tenant->id}", ['reason' => 'Support case 4711']);

    $this->delete('/admin/acting-as')->assertRedirect(route('dashboard'));

    $this->get('/_test/context')->assertExactJson(['tenant' => $this->admin->fresh()->current_team_id]);
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('actingAs', null));

    Log::shouldHaveReceived('info')->with('acting_as.stopped', Mockery::on(fn ($context) => $context['tenant_id'] === $this->tenant->id));
});

test('acting-as state in the session is ignored for a user who is not a platform admin', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)
        ->withSession(['acting_as' => ['admin_id' => $user->id, 'tenant_id' => $this->tenant->id, 'reason' => 'x', 'started_at' => '']])
        ->get('/_test/context')
        ->assertExactJson(['tenant' => $user->current_team_id]);
});

test('acting-as state belonging to another admin is ignored', function () {
    $other = User::factory()->platformAdmin()->create();

    $this->actingAs($this->admin)
        ->withSession(['acting_as' => ['admin_id' => $other->id, 'tenant_id' => $this->tenant->id, 'reason' => 'x', 'started_at' => '']])
        ->get('/_test/context')
        ->assertExactJson(['tenant' => $this->admin->fresh()->current_team_id]);
});

test('acting as a tenant does not grant other tenants', function () {
    $other = Team::factory()->tenant()->create();

    $this->actingAs($this->admin)->withSession(confirmedPassword())
        ->post("/admin/acting-as/{$this->tenant->id}", ['reason' => 'Support case 4711']);

    expect(app(TenantAccess::class)->canAccess($this->admin, $other))->toBeFalse();
});
