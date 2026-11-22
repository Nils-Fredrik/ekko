<?php

use App\Enums\ActionStatus;
use App\Models\Action;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Workspace\WorkspaceCounts;
use Inertia\Testing\AssertableInertia;

// U17: the shell's shared `workspace` prop, its counts and the setup routes (docs/ux-arbeidsflate.md 4.1 and 6.1).

/** @return array{owner: User, tenant: Team, brand: Brand, norway: Market, sweden: Market} */
function workspaceWorld(): array
{
    $owner = User::factory()->withPersonalTeam()->create();
    $tenant = Team::factory()->tenant()->withPlan(Plan::factory()->create(['monthly_call_quota' => 200]))->create([
        'user_id' => $owner->id,
        'name' => 'Workspace tenant',
    ]);
    $owner->switchTeam($tenant);

    return app(TenantContext::class)->run($tenant->id, function () use ($owner, $tenant): array {
        Brand::factory()->create(['name' => 'Zeta brand']);
        $brand = Brand::factory()->create(['name' => 'Alpha brand']);
        $norway = Market::factory()->for($brand)->create(['country' => 'NO', 'language' => 'nb']);
        $sweden = Market::factory()->for($brand)->create(['country' => 'SE', 'language' => 'sv', 'location_label' => 'Stockholm', 'is_active' => false]);

        return ['owner' => $owner, 'tenant' => $tenant, 'brand' => $brand, 'norway' => $norway, 'sweden' => $sweden];
    });
}

/** @return array<string, mixed>|null the deferred workspace prop of a page */
function workspaceProp(mixed $test, User $user, string $url): ?array
{
    $workspace = false;
    $test->actingAs($user)->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$workspace) {
        $page->missing('workspace')->loadDeferredProps('workspace', function (AssertableInertia $deferred) use (&$workspace) {
            $workspace = $deferred->toArray()['props']['workspace'] ?? null;
        });
    });
    expect($workspace)->not->toBeFalse();

    return $workspace;
}

test('the workspace prop is deferred and holds the brand, its markets and the market in the address', function () {
    $w = workspaceWorld();

    $workspace = workspaceProp($this, $w['owner'], "/brands/{$w['brand']->id}/sources?market={$w['norway']->id}");

    expect($workspace['brands'])->toBe([
        ['id' => $w['brand']->id, 'name' => 'Alpha brand'],
        ['id' => $workspace['brands'][1]['id'], 'name' => 'Zeta brand'],
    ])
        ->and($workspace['brand'])->toBe(['id' => $w['brand']->id, 'name' => 'Alpha brand'])
        ->and($workspace['markets'])->toBe([
            ['id' => $w['norway']->id, 'label' => 'Norge', 'is_active' => true],
            ['id' => $w['sweden']->id, 'label' => 'Stockholm', 'is_active' => false],
        ])
        ->and($workspace['market_id'])->toBe($w['norway']->id)
        ->and($workspace['role'])->toBe('roles.admin.name')
        ->and($workspace['can'])->toBe([
            'manage_prompts' => true,
            'manage_competitors' => true,
            'manage_brands' => true,
            'manage_markets' => true,
            'manage_alerts' => true,
            'manage_reports' => true,
        ]);
});

test('a global page has no brand, and a market of another brand is ignored', function () {
    $w = workspaceWorld();
    $other = app(TenantContext::class)->run($w['tenant']->id, fn () => Market::factory()->create());

    $global = workspaceProp($this, $w['owner'], '/dashboard');
    expect($global['brand'])->toBeNull()->and($global['markets'])->toBe([])->and($global['market_id'])->toBeNull();

    $foreign = workspaceProp($this, $w['owner'], "/brands/{$w['brand']->id}?market={$other->id}");
    expect($foreign['brand']['id'])->toBe($w['brand']->id)->and($foreign['market_id'])->toBeNull();
});

test('the counts match the waiting alerts, new actions and failing integrations', function () {
    $w = workspaceWorld();
    app(TenantContext::class)->run($w['tenant']->id, function () use ($w): void {
        Alert::factory()->count(2)->create();
        Alert::factory()->create(['acknowledged_at' => now()]);
        Alert::factory()->create(['triggered_at' => now()->subDays(WorkspaceCounts::INBOX_DAYS + 1)]);
        Action::factory()->for($w['brand'])->count(3)->create();
        Action::factory()->for($w['brand'])->create(['status' => ActionStatus::Planned]);
        Action::factory()->create();
        Integration::factory()->for($w['brand'])->create(['status' => 'error']);
        Integration::factory()->for($w['brand'])->create(['type' => 'clickhouse_logs', 'status' => 'ok']);
    });

    $workspace = workspaceProp($this, $w['owner'], "/brands/{$w['brand']->id}/actions");

    expect($workspace['counts'])->toBe([
        'inbox' => 2,
        'actions_new' => 3,
        'entities_pending' => 0,
        'integrations_failing' => 1,
    ]);
    expect(workspaceProp($this, $w['owner'], '/alerts')['counts'])->toBe([
        'inbox' => 2,
        'actions_new' => 0,
        'entities_pending' => 0,
        'integrations_failing' => 0,
    ]);
});

test('the counts are cached for a minute and forgotten when a source changes', function () {
    $w = workspaceWorld();
    $alert = app(TenantContext::class)->run($w['tenant']->id, fn () => Alert::factory()->create());
    $counts = fn (): array => app(TenantContext::class)->run($w['tenant']->id, fn () => WorkspaceCounts::for($w['tenant'], $w['brand']));

    expect($counts()['inbox'])->toBe(1);

    // A query-builder write fires no model event, so the cached count stays until it expires.
    app(TenantContext::class)->run($w['tenant']->id, fn () => Alert::query()->whereKey($alert->id)->update(['acknowledged_at' => now()]));
    expect($counts()['inbox'])->toBe(1);
    $this->travel(WorkspaceCounts::TTL_SECONDS + 1)->seconds();
    expect($counts()['inbox'])->toBe(0);

    app(TenantContext::class)->run($w['tenant']->id, fn () => Alert::factory()->create());
    expect($counts()['inbox'])->toBe(1);
    app(TenantContext::class)->run($w['tenant']->id, fn () => Action::factory()->for($w['brand'])->create());
    expect($counts()['actions_new'])->toBe(1);
});

test('the usage shows this month against the quota for those who may see it', function () {
    $w = workspaceWorld();
    app(TenantContext::class)->run($w['tenant']->id, fn () => UsageEvent::factory()->create(['calls' => 50, 'cost_usd' => '1.25000000']));

    $usage = workspaceProp($this, $w['owner'], '/dashboard')['usage'];
    expect($usage)->toBe([
        'month' => now('UTC')->format('Y-m'),
        'calls' => 50,
        'quota' => 200,
        'cost_usd' => '1.25000000',
        'percent' => 25,
    ]);

    $reader = User::factory()->withPersonalTeam()->create();
    $w['tenant']->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($w['tenant']);

    $workspace = workspaceProp($this, $reader, "/brands/{$w['brand']->id}");
    expect($workspace['usage'])->toBeNull()
        ->and($workspace['role'])->toBe('roles.reader.name')
        ->and(array_filter($workspace['can']))->toBe([]);
});

test('a guest gets no workspace and no deferred request for it', function () {
    $this->get('/login')->assertOk()->assertInertia(function (AssertableInertia $page) {
        $page->where('workspace', null);
        expect($page->toArray()['deferredProps'] ?? [])->not->toHaveKey('workspace');
    });
});

test('the setup routes forward to the pages of today and keep the market where it is read', function () {
    $w = workspaceWorld();
    $brand = $w['brand']->id;
    $market = $w['norway']->id;
    $this->actingAs($w['owner']);

    $this->get("/brands/{$brand}/setup/prompts?market={$market}")->assertRedirect("/brands/{$brand}/prompts?market={$market}");
    $this->get("/brands/{$brand}/setup/competitors?market={$market}")->assertRedirect("/brands/{$brand}/competitors?market={$market}");
    $this->get("/brands/{$brand}/setup/prompts?market=999999")->assertRedirect("/brands/{$brand}/prompts");
    foreach (['markets', 'identity', 'integrations'] as $page) {
        $this->get("/brands/{$brand}/setup/{$page}?market={$market}")->assertRedirect("/brands/{$brand}/settings");
    }
    $this->get("/brands/{$brand}/setup/notifications")->assertRedirect('/alerts');
    $this->get('/inbox')->assertRedirect('/alerts');
});
