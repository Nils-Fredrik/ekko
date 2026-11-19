<?php

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
