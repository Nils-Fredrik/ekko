<?php

// U17: the shell's `workspace` prop, the badge counts and the redirects for the sidebar's pages
// (docs/ux-arbeidsflate.md 4.1 and 6.1). Synthetic data only.

use App\Enums\ActionStatus;
use App\Models\Action;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\Market;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Workspace\WorkspaceCounts;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Metrics\MetricsWorld;

function shellWorld(): MetricsWorld
{
    $world = MetricsWorld::create();
    $world->tenant->owner->switchTeam($world->tenant);
    Cache::flush();

    return $world;
}

function shellMember(MetricsWorld $world, string $role): User
{
    $user = User::factory()->create();
    $world->tenant->users()->attach($user, ['role' => $role]);
    $user->switchTeam($world->tenant);

    return $user;
}

test('a brand page defers the workspace with brands, markets, counts, usage and rights', function () {
    $world = shellWorld();
    $sweden = Market::factory()->for($world->brand)->create(['country' => 'SE', 'language' => 'sv']);
    $labelled = Market::factory()->for($world->brand)->create(['country' => 'DK', 'location_label' => 'København', 'is_active' => false]);
    $other = Brand::factory()->create(['name' => 'Annet merke']);
    Action::factory()->create(['brand_id' => $world->brand->id, 'market_id' => $world->market->id]);
    Action::factory()->create(['brand_id' => $world->brand->id, 'market_id' => $sweden->id]);

    $this->actingAs($world->tenant->owner)
        ->get(route('brands.actions.index', [$world->brand, 'market' => $sweden->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('workspace')
            ->loadDeferredProps('workspace', fn (AssertableInertia $deferred) => $deferred
                ->where('workspace.brand', ['id' => $world->brand->id, 'name' => 'Fjordkaffe'])
                ->where('workspace.brands', [
                    ['id' => $other->id, 'name' => 'Annet merke'],
                    ['id' => $world->brand->id, 'name' => 'Fjordkaffe'],
                ])
                ->where('workspace.markets', [
                    ['id' => $world->market->id, 'label' => 'Norge', 'is_active' => true],
                    ['id' => $sweden->id, 'label' => 'Sverige', 'is_active' => true],
                    ['id' => $labelled->id, 'label' => 'København', 'is_active' => false],
                ])
                ->where('workspace.market_id', $sweden->id)
                ->where('workspace.counts', ['inbox' => 1, 'actions_new' => 1, 'entities_pending' => 0, 'integrations_failing' => 0])
                ->where('workspace.usage.month', now('UTC')->format('Y-m'))
                ->where('workspace.usage.calls', 0)
                ->where('workspace.role', 'Tenant-admin')
                ->where('workspace.can', [
                    'manage_prompts' => true, 'manage_competitors' => true, 'manage_brands' => true,
                    'manage_markets' => true, 'manage_alerts' => true, 'manage_reports' => true,
                ])));
});

test('the market follows the URL, and a market of another brand is no market', function () {
    $world = shellWorld();
    $other = Market::factory()->for(Brand::factory())->create();
    $owner = $world->tenant->owner;

    foreach ([
        [['market' => $world->market->id], $world->market->id],
        [['market' => $other->id], null],
        [['market' => 'abc'], null],
        [[], null],
    ] as [$query, $expected]) {
        $this->actingAs($owner)->get(route('brands.facts.index', [$world->brand, ...$query]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps('workspace', fn (AssertableInertia $deferred) => $deferred
                    ->where('workspace.market_id', $expected)));
    }
});

test('a global page has the workspace without a brand', function () {
    $world = shellWorld();

    $this->actingAs($world->tenant->owner)->get(route('inbox.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps('workspace', fn (AssertableInertia $deferred) => $deferred
                ->where('workspace.brand', null)
                ->where('workspace.markets', [])
                ->where('workspace.market_id', null)
                ->has('workspace.brands', 1)));
    $this->actingAs($world->tenant->owner)->get(route('inbox.index', ['brand' => $world->brand->id, 'market' => $world->market->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', $world->brand->id)
            ->where('filters.market', $world->market->id)
            ->loadDeferredProps('workspace', fn (AssertableInertia $deferred) => $deferred
                ->where('workspace.brand.id', $world->brand->id)
                ->where('workspace.market_id', $world->market->id)
                ->where('workspace.counts.inbox', 0)));
});

test('rights and usage follow the role', function () {
    $world = shellWorld();

    $this->actingAs(shellMember($world, 'reader'))->get(route('brands.show', $world->brand))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps('workspace', fn (AssertableInertia $deferred) => $deferred
                ->where('workspace.role', 'Leser')
                ->where('workspace.usage', null)
                ->where('workspace.can', [
                    'manage_prompts' => false, 'manage_competitors' => false, 'manage_brands' => false,
                    'manage_markets' => false, 'manage_alerts' => false, 'manage_reports' => false,
                ])));

    $this->actingAs(shellMember($world, 'editor'))->get(route('brands.show', $world->brand))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps('workspace', fn (AssertableInertia $deferred) => $deferred
                ->where('workspace.can.manage_prompts', true)
                ->where('workspace.can.manage_competitors', true)
                ->where('workspace.can.manage_brands', false)
                ->where('workspace.can.manage_reports', false)));
});

test('guests get no workspace', function () {
    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->missing('workspace'));
});

test('the counts come from WorkspaceCounts and match the sidebar badges', function () {
    $world = shellWorld();
    Action::factory()->count(2)->create(['brand_id' => $world->brand->id]);
    Action::factory()->create(['brand_id' => $world->brand->id, 'status' => ActionStatus::Done]);
    Integration::factory()->create(['brand_id' => $world->brand->id, 'status' => 'error']);
    Integration::factory()->create(['brand_id' => $world->brand->id, 'type' => 'clickhouse_logs', 'status' => 'synced']);
    $rule = AlertRule::factory()->create(['brand_id' => $world->brand->id]);
    Alert::factory()->count(3)->create(['alert_rule_id' => $rule->id]);
    Alert::factory()->create(['alert_rule_id' => $rule->id, 'acknowledged_at' => now()]);
    // An unknown brand in two answers is one name in the queue.
    $world->answer($world->p1, now()->subDay()->toDateTimeString(), ['unknown' => 'Beste Byrå']);
    $world->answer($world->p2, now()->subDay()->toDateTimeString(), ['unknown' => 'Beste Byrå']);

    // One alert group, one unknown name, two actions and one failing integration.
    $expected = ['inbox' => 5, 'actions_new' => 2, 'entities_pending' => 1, 'integrations_failing' => 1];
    expect(WorkspaceCounts::for($world->tenant, $world->brand))->toBe($expected)
        ->and(WorkspaceCounts::for($world->tenant, null))
        ->toBe(['inbox' => 5, 'actions_new' => 0, 'entities_pending' => 0, 'integrations_failing' => 0]);

    $this->actingAs($world->tenant->owner)->get(route('brands.show', $world->brand))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps('workspace', fn (AssertableInertia $deferred) => $deferred
                ->where('workspace.counts', $expected)));
});

test('the counts are cached and cleared when a change behind them is saved', function () {
    $world = shellWorld();
    $rule = AlertRule::factory()->create(['brand_id' => $world->brand->id]);
    $alert = Alert::factory()->create(['alert_rule_id' => $rule->id]);
    expect(WorkspaceCounts::for($world->tenant, $world->brand)['inbox'])->toBe(1);

    // A write that skips the model events is not seen until the cache expires.
    Alert::query()->insert([
        'tenant_id' => $world->tenant->id, 'alert_rule_id' => $rule->id, 'triggered_at' => now(),
        'payload' => json_encode(['change_percentage_points' => -1]),
    ]);
    expect(WorkspaceCounts::for($world->tenant, $world->brand)['inbox'])->toBe(1);
    $this->travel(WorkspaceCounts::TTL_SECONDS + 1)->seconds();
    expect(WorkspaceCounts::for($world->tenant, $world->brand)['inbox'])->toBe(2);

    // Acknowledging an alert clears the counts at once.
    $alert->update(['acknowledged_at' => now()]);
    expect(WorkspaceCounts::for($world->tenant, $world->brand)['inbox'])->toBe(1);

    Action::factory()->create(['brand_id' => $world->brand->id]);
    expect(WorkspaceCounts::for($world->tenant, $world->brand)['actions_new'])->toBe(1);
});

test('the counts of one tenant are not another tenant\'s', function () {
    $a = shellWorld();
    Action::factory()->create(['brand_id' => $a->brand->id]);
    expect(WorkspaceCounts::for($a->tenant, $a->brand)['actions_new'])->toBe(1);
    $b = MetricsWorld::create();
    app(TenantContext::class)->set($b->tenant->id);

    expect(WorkspaceCounts::for($b->tenant, $b->brand)['actions_new'])->toBe(0)
        ->and(fn () => WorkspaceCounts::for($a->tenant, $a->brand))->toThrow(LogicException::class);
});

test('the setup routes send the user to today\'s pages until each page is built', function () {
    $world = shellWorld();
    $owner = $world->tenant->owner;
    $brand = $world->brand;

    $this->actingAs($owner)->get(route('brands.setup.prompts', [$brand, 'market' => $world->market->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Setup/Prompts')->where('canManage', true));
    $this->actingAs($owner)->get(route('brands.setup.prompts', $brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Setup/Prompts')->where('market', null));
    $this->actingAs($owner)->get(route('brands.setup.competitors', [$brand, 'market' => $world->market->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Setup/Competitors')->where('market.id', $world->market->id));
    $this->actingAs($owner)->get(route('brands.setup.markets', $brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Setup/Markets')->where('canManage', true)->has('measurementPlan'));
    $this->actingAs($owner)->get(route('brands.setup.identity', $brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Setup/Identity')->where('canManage', true));
    $this->actingAs($owner)->get(route('brands.setup.integrations', $brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Setup/Integrations')->where('canManage', true));
    $this->actingAs($owner)->get(route('brands.settings', $brand))
        ->assertRedirect(route('brands.setup.identity', $brand));
    $this->actingAs($owner)->get(route('brands.setup.notifications', $brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Setup/Notifications')->where('canAlerts', true)->has('rules', 7));
    $this->actingAs($owner)->get(route('inbox.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inbox/Index')->where('counts.all', 0));
    $this->actingAs($owner)->get(route('alerts.index'))
        ->assertRedirect(route('inbox.index', ['type' => 'alerts']));
});

test('a reader can open the setup routes, which show the pages without change buttons', function () {
    $world = shellWorld();
    $reader = shellMember($world, 'reader');

    $this->actingAs($reader)->get(route('brands.setup.prompts', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Setup/Prompts')->where('canManage', false));
    $this->actingAs($reader)->get(route('brands.setup.identity', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Setup/Identity')->where('canManage', false));
    $this->actingAs($reader)->get(route('brands.setup.markets', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Setup/Markets')->where('canManage', false));
    $this->actingAs($reader)->get(route('inbox.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inbox/Index')->where('can.alerts', false));
    $this->actingAs($reader)->get(route('brands.setup.notifications', $world->brand))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Setup/Notifications')->where('canAlerts', false)->where('canReports', false));
});
