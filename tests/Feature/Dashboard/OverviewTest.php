<?php

use App\Models\AggDaily;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\ModelChange;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\Topic;
use App\Models\UsageMonthly;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Metrics\MetricsWorld;

test('the overview shows the tenant brands with visibility and n, and nothing from another tenant', function () {
    $world = MetricsWorld::create();
    $today = CarbonImmutable::now('Europe/Oslo')->toDateString();
    AggDaily::factory()->create([
        'market_id' => $world->market->id,
        'engine_key' => $world->engine->key,
        'prompt_id' => $world->p1->id,
        'date' => $today,
        'responses' => 20,
        'brand_mentioned' => 10,
        'competitor_mentions' => [$world->c1->id => 10],
    ]);
    Alert::factory()->create([
        'alert_rule_id' => AlertRule::factory()->create(['brand_id' => $world->brand->id])->id,
    ]);
    RunBatch::factory()->create([
        'market_id' => $world->market->id,
        'scheduled_for' => CarbonImmutable::now()->addDay(),
        'status' => 'queued',
    ]);
    UsageMonthly::factory()->create(['calls' => 12, 'quota_calls' => 100]);

    $secret = 'Hemmeligmerke';
    app(TenantContext::class)->run(
        Team::factory()->tenant()->create()->id,
        fn () => Brand::factory()->create(['name' => $secret]),
    );

    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Home')
            ->where('botLogUploadsUrl', route('bot-log-uploads.index', $world->tenant))
            ->where('brands', fn ($brands) => collect($brands)->pluck('name')->all() === ['Fjordkaffe'])
            ->where('brands.0.visibility.value', 0.5)
            ->where('brands.0.visibility.n', 20)
            ->where('brands.0.visibility.low_sample', false)
            ->has('alerts', 1)
            ->has('runs', 1)
            ->where('usage.calls', 12)
            ->where('usage.quota_calls', 100));
});

test('a reader sees the overview but not usage', function () {
    $world = MetricsWorld::create();
    UsageMonthly::factory()->create(['calls' => 12]);
    $reader = User::factory()->create();
    $world->tenant->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($world->tenant);

    $this->actingAs($reader)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('usage', null)->where('botLogUploadsUrl', null)->where('brands.0.name', 'Fjordkaffe'));
});

test('the brand dashboard follows the engine filter and shows a model change', function () {
    $world = MetricsWorld::create();
    $today = CarbonImmutable::now('Europe/Oslo')->toDateString();
    $other = Engine::factory()->create();
    MarketEngine::factory()->create(['market_id' => $world->market->id, 'engine_key' => $world->engine->key]);
    MarketEngine::factory()->create(['market_id' => $world->market->id, 'engine_key' => $other->key]);
    AggDaily::factory()->create([
        'market_id' => $world->market->id,
        'engine_key' => $world->engine->key,
        'prompt_id' => $world->p1->id,
        'date' => $today,
        'responses' => 20,
        'brand_mentioned' => 10,
    ]);
    ModelChange::factory()->create([
        'engine_key' => $world->engine->key,
        'changed_on' => $today,
        'from_model' => 'model-a',
        'to_model' => 'model-next',
    ]);

    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->get('/brands/'.$world->brand->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Brands/Show')
            ->where('summary.visibility.value', 0.5)
            ->where('summary.visibility.n', 20)
            ->where('modelChanges.0.to_model', 'model-next')
            ->where('voice.0.name', 'Fjordkaffe'));

    $this->actingAs($owner)->get('/brands/'.$world->brand->id.'?engine='.$other->key)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.visibility.value', null)
            ->where('summary.visibility.n', 0)
            ->where('filters.engine', $other->key));
});

test('a brand of another tenant is not reachable', function () {
    $world = MetricsWorld::create();
    $foreign = app(TenantContext::class)->run(
        Team::factory()->tenant()->create()->id,
        fn () => Brand::factory()->create()->id,
    );
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->get('/brands/'.$foreign)->assertNotFound();
});

test('guests are sent to the login page', function () {
    $this->get('/dashboard')->assertRedirect();
    $this->get('/brands')->assertRedirect();
});

test('the brand list shows the translated status and markets, and hides other tenants', function () {
    $world = MetricsWorld::create();
    $world->market->update(['location_label' => 'Norge']);
    app(TenantContext::class)->run(
        Team::factory()->tenant()->create()->id,
        fn () => Brand::factory()->create(['name' => 'Hemmeligmerke']),
    );
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->get('/brands')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Brands/Index')
            ->where('brands', fn ($brands) => collect($brands)->pluck('name')->all() === ['Fjordkaffe'])
            ->where('brands.0.status', 'Aktiv')
            ->where('brands.0.markets.0.label', 'Norge'));
});

test('a market or topic from another brand is ignored', function () {
    $world = MetricsWorld::create();
    $today = CarbonImmutable::now('Europe/Oslo')->toDateString();
    AggDaily::factory()->create([
        'market_id' => $world->market->id,
        'engine_key' => $world->engine->key,
        'prompt_id' => $world->p1->id,
        'date' => $today,
        'responses' => 20,
        'brand_mentioned' => 10,
    ]);
    $foreign = app(TenantContext::class)->run($world->tenant->id, function () {
        $brand = Brand::factory()->create();

        return [
            'market' => Market::factory()->for($brand)->create()->id,
            'topic' => Topic::factory()->create(['brand_id' => $brand->id])->id,
        ];
    });
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)
        ->get('/brands/'.$world->brand->id.'?market='.$foreign['market'].'&topic='.$foreign['topic'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.visibility.value', 0.5)
            ->where('summary.visibility.n', 20)
            ->where('filters.market', null)
            ->where('filters.topic', null));
});

test('the period follows the tenant being acted as', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'));

    try {
        $admin = User::factory()->platformAdmin()->withPersonalTeam()->create();
        DB::table('teams')->where('id', $admin->current_team_id)->update(['timezone' => 'America/Los_Angeles']);
        $tenant = Team::factory()->tenant()->create();
        DB::table('teams')->where('id', $tenant->id)->update(['timezone' => 'Pacific/Auckland']);
        app(TenantContext::class)->run($tenant->id, fn () => Brand::factory()->create());

        $this->actingAs($admin)->withSession([
            'acting_as' => [
                'admin_id' => $admin->id,
                'tenant_id' => $tenant->id,
                'reason' => 'Support case 4711',
                'started_at' => now()->toIso8601String(),
            ],
        ])->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period.to', '2026-11-16'));
    } finally {
        CarbonImmutable::setTestNow();
    }
});
