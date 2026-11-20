<?php

use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Action;
use App\Models\AggDaily;
use App\Models\BotHitDaily;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\MarketEngine;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Metrics\MetricsWorld;

test('graph data is deferred and the brand dashboard respects the selected engine', function () {
    $world = MetricsWorld::create();
    $today = CarbonImmutable::now('Europe/Oslo')->toDateString();
    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'engine_key' => $world->engine->key,
        'prompt_id' => $world->p1->id, 'date' => $today,
        'responses' => 20, 'brand_mentioned' => 10,
    ]);
    $action = Action::factory()->create(['brand_id' => $world->brand->id, 'title' => 'Syntetisk tiltak', 'started_at' => now(), 'completed_at' => null]);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $other = Engine::factory()->create();
    MarketEngine::factory()->create(['market_id' => $world->market->id, 'engine_key' => $other->key]);
    Cache::flush();

    $this->actingAs($owner)->get('/brands/'.$world->brand->id)
        ->assertOk()->assertInertia(fn ($page) => $page
        ->missing('chartData')
        ->loadDeferredProps(fn ($deferred) => $deferred
            ->where('chartData.series.0.metrics.visibility.value', 0.5)
            ->where('chartData.series.0.metrics.visibility.n', 20)
            ->where('chartData.action_markers.0.action_id', $action->id)
            ->where('chartData.engines.0.engine_key', $world->engine->key)));

    $this->actingAs($owner)->get('/brands/'.$world->brand->id.'?engine='.$other->key)
        ->assertOk()->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($deferred) => $deferred
            ->where('chartData.series', [])
            ->where('chartData.engines', [])));
});

test('each chart page computes deferred data and refuses another tenant even after cache warmup', function () {
    $world = MetricsWorld::create();
    $today = CarbonImmutable::now('Europe/Oslo')->toDateString();
    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'engine_key' => $world->engine->key,
        'prompt_id' => $world->p1->id, 'date' => $today,
        'responses' => 10, 'brand_mentioned' => 6,
    ]);
    BotHitDaily::factory()->create(['brand_id' => $world->brand->id, 'date' => $today, 'hits' => 12]);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    Cache::flush();

    foreach (['prompts', 'competitors', 'sources', 'crawlers'] as $page) {
        $this->actingAs($owner)->get('/brands/'.$world->brand->id.'/'.$page)
            ->assertOk()->assertInertia(fn ($view) => $view
            ->missing('chartData')
            ->loadDeferredProps(fn ($deferred) => $deferred->has('chartData')));
    }

    $other = Team::factory()->tenant()->create();
    $foreign = app(TenantContext::class)->run($other->id, fn () => Brand::factory()->create());
    $otherOwner = $other->owner;
    $otherOwner->switchTeam($other);
    foreach (['', '/prompts', '/competitors', '/sources', '/crawlers'] as $suffix) {
        $this->actingAs($otherOwner)->get('/brands/'.$world->brand->id.$suffix)->assertNotFound();
        $this->actingAs($owner)->get('/brands/'.$foreign->id.$suffix)->assertNotFound();
    }
});

test('overview graph data is deferred and scoped to the current tenant', function () {
    $world = MetricsWorld::create();
    $today = CarbonImmutable::now('Europe/Oslo')->toDateString();
    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'engine_key' => $world->engine->key,
        'prompt_id' => $world->p1->id, 'date' => $today,
        'responses' => 10, 'brand_mentioned' => 6,
    ]);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    Cache::flush();
    $this->actingAs($owner)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->missing('chartData')
        ->loadDeferredProps(fn ($deferred) => $deferred
            ->has('chartData', 1)
            ->where('chartData.0.brand_id', $world->brand->id)
            ->where('chartData.0.series.0.visibility.value', 0.6)));
});

test('fixed sparkline and stability windows are independent of the seven-day page filter', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Europe/Oslo'));
    $world = MetricsWorld::create();
    foreach (['2026-11-08', '2026-11-18'] as $date) {
        AggDaily::factory()->create([
            'market_id' => $world->market->id, 'engine_key' => $world->engine->key,
            'prompt_id' => $world->p1->id, 'date' => $date,
            'responses' => 10, 'brand_mentioned' => 6,
        ]);
    }
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    Cache::flush();

    $this->actingAs($owner)->get('/brands/'.$world->brand->id.'?days=7&grain=week')
        ->assertOk()->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($deferred) => $deferred
            ->has('chartData.sparkline', 2)
            ->where('chartData.engines.0.period', '2026-11-16')));
    $this->actingAs($owner)->get('/brands/'.$world->brand->id.'/prompts?days=7')
        ->assertOk()->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($deferred) => $deferred
            ->has('chartData.stability', 2)
            ->where('chartData.stability.0.period', '2026-11-08')));
});

test('dashboard comparison follows weekly and monthly grain while the sparkline stays daily', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Europe/Oslo'));
    $world = MetricsWorld::create();
    foreach ([['2026-11-17', 4, 2], ['2026-11-18', 6, 3], ['2026-10-15', 2, 0], ['2026-10-16', 3, 3]] as [$date, $responses, $mentioned]) {
        AggDaily::factory()->create([
            'market_id' => $world->market->id, 'engine_key' => $world->engine->key,
            'prompt_id' => $world->p1->id, 'date' => $date,
            'responses' => $responses, 'brand_mentioned' => $mentioned,
        ]);
    }
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    Cache::flush();

    foreach (['week' => ['2026-11-16', '2026-10-12'], 'month' => ['2026-11-01', '2026-10-01']] as $grain => [$current, $previous]) {
        $this->actingAs($owner)->get('/brands/'.$world->brand->id.'?days=28&grain='.$grain)
            ->assertOk()->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($data) => $data
                ->has('chartData.series', 1)
                ->where('chartData.series.0.period', $current)
                ->where('chartData.series.0.metrics.visibility.value', 0.5)
                ->where('chartData.series.0.metrics.visibility.n', 10)
                ->has('chartData.previous', 1)
                ->where('chartData.previous.0.period', $previous)
                ->where('chartData.previous.0.metrics.visibility.value', 0.6)
                ->where('chartData.previous.0.metrics.visibility.n', 5)
                ->has('chartData.sparkline', 2)
                ->where('chartData.sparkline.0.period', '2026-11-17')
                ->where('chartData.sparkline.1.period', '2026-11-18')
                ->where('chartData.engines.0.period', $current)
                ->where('chartData.competitors.0.period', $current)));
    }
});

test('the brand dashboard keeps the three highest open actions and links their markers', function () {
    $world = MetricsWorld::create();
    $otherBrand = Brand::factory()->create();
    $fact = Action::factory()->create([
        'brand_id' => $world->brand->id, 'type' => ActionType::FactCorrection, 'title' => 'Fact',
        'impact_level' => 1, 'impact_score' => 1, 'status' => ActionStatus::New, 'started_at' => now(),
    ]);
    $high = Action::factory()->create([
        'brand_id' => $world->brand->id, 'title' => 'High', 'impact_level' => 5, 'impact_score' => 1,
        'status' => ActionStatus::Planned, 'started_at' => now(),
    ]);
    $mid = Action::factory()->create([
        'brand_id' => $world->brand->id, 'title' => 'Mid', 'impact_level' => 4, 'impact_score' => 1,
        'status' => ActionStatus::InProgress, 'started_at' => now(),
    ]);
    Action::factory()->create([
        'brand_id' => $world->brand->id, 'title' => 'Low', 'impact_level' => 2, 'impact_score' => 9,
        'status' => ActionStatus::New,
    ]);
    Action::factory()->create([
        'brand_id' => $world->brand->id, 'title' => 'Done', 'impact_level' => 5, 'impact_score' => 9,
        'status' => ActionStatus::Done, 'completed_at' => now(),
    ]);
    Action::factory()->create([
        'brand_id' => $otherBrand->id, 'title' => 'Other brand', 'impact_level' => 5, 'impact_score' => 9,
    ]);
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    Cache::flush();

    $this->actingAs($owner)->get('/brands/'.$world->brand->id)
        ->assertOk()->assertInertia(function ($page) use ($world, $fact, $high, $mid) {
            $page->has('topActions', 3)
                ->where('topActions.0.id', $fact->id)
                ->where('topActions.1.id', $high->id)
                ->where('topActions.2.id', $mid->id)
                ->loadDeferredProps(function ($deferred) use ($world, $fact) {
                    $markers = $deferred->toArray()['props']['chartData']['action_markers'];
                    $match = collect($markers)->firstWhere('action_id', $fact->id);
                    expect($match['href'] ?? null)->toBe(route('brands.actions.show', [$world->brand, $fact]));
                });
        });
});

test('insight text is on unless the environment switches it off', function () {
    expect(config('ekko.insights.enabled'))->toBeTrue();
});
