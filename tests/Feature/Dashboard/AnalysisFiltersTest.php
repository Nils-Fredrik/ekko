<?php

use App\Dashboard\AnalysisFilters;
use App\Dashboard\DashboardPeriod;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AggDaily;
use App\Models\Engine;
use App\Models\MarketEngine;
use App\Models\Prompt;
use App\Models\SavedView;
use App\Models\Topic;
use App\Models\User;
use App\Tenancy\TenantContext;
use Tests\Support\Metrics\MetricsWorld;

test('custom periods compare with the previous window or the same calendar dates last year', function () {
    MetricsWorld::create('Europe/Oslo');
    $previous = DashboardPeriod::fromInput(['from' => '2024-02-29', 'to' => '2024-03-02']);
    $year = DashboardPeriod::fromInput(['from' => '2024-02-29', 'to' => '2024-03-02', 'comparison' => 'year']);
    expect($previous->days)->toBe(3)
        ->and([$previous->previousFrom, $previous->previousTo])->toBe(['2024-02-26', '2024-02-28'])
        ->and([$year->previousFrom, $year->previousTo])->toBe(['2023-02-28', '2023-03-02']);
});

test('partial filter requests refresh data and cached comparison results separately', function () {
    $world = MetricsWorld::create();
    $world->tenant->owner->switchTeam($world->tenant);
    foreach ([['2026-10-02', 10], ['2026-09-30', 6], ['2025-10-02', 2]] as [$date, $mentioned]) {
        AggDaily::factory()->create(['market_id' => $world->market->id, 'prompt_id' => $world->p1->id,
            'engine_key' => $world->engine->key, 'date' => $date, 'responses' => 20, 'brand_mentioned' => $mentioned]);
    }
    $url = route('brands.show', $world->brand).'?from=2026-10-01&to=2026-10-03&grain=day';
    $this->actingAs($world->tenant->owner)->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('previous.visibility.value', 0.3)
        ->loadDeferredProps(fn ($data) => $data->where('chartData.previous.0.metrics.visibility.value', 0.3)));
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()), 'X-Inertia-Partial-Component' => 'Brands/Show',
        'X-Inertia-Partial-Data' => 'period,analysisFilters,previous,chartData'])->get($url.'&comparison=year')
        ->assertOk()->assertJsonPath('props.previous.visibility.value', 0.1)
        ->assertJsonPath('props.chartData.previous.0.metrics.visibility.value', 0.1)
        ->assertJsonPath('props.period.previous_from', '2025-10-01')
        ->assertJsonMissingPath('props.brand')->assertJsonMissingPath('props.options');
});

test('every analysis page resolves custom dates and rejects invalid or oversized windows', function (string $page) {
    $world = MetricsWorld::create();
    $world->tenant->owner->switchTeam($world->tenant);
    $params = ['brand' => $world->brand->id];
    if ($page === 'brands.prompts.show') {
        $params['prompt'] = $world->p1->id;
    }
    $url = route($page, $params);
    $this->actingAs($world->tenant->owner)->get($url.'?from=2026-10-01&to=2026-10-03&comparison=year')
        ->assertOk()->assertInertia(fn ($data) => $data->where('period.from', '2026-10-01')->where('period.to', '2026-10-03')
        ->where('period.previous_from', '2025-10-01')->where('analysisFilters.query.comparison', 'year'));
    foreach (['from=2026-02-30&to=2026-03-01', 'from=2026-10-03&to=2026-10-01', 'from=2024-01-01&to=2026-01-01', 'from=2026-10-01'] as $bad) {
        $this->getJson($url.'?'.$bad)->assertUnprocessable();
    }
})->with(AnalysisFilters::PAGES);

test('saved views belong to one user, brand and tenant and support readers', function () {
    $world = MetricsWorld::create();
    $reader = User::factory()->create();
    $world->tenant->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($world->tenant);
    $payload = ['name' => 'Weekly view', 'page' => 'brands.show', 'filters' => ['days' => 7, 'engine' => $world->engine->key]];
    $id = $this->actingAs($reader)->postJson(route('brands.views.store', $world->brand), $payload)->assertCreated()->json('id');
    $this->get(route('brands.show', $world->brand))->assertOk()->assertInertia(fn ($page) => $page
        ->where('analysisFilters.views.0.id', $id)->where('analysisFilters.views.0.filters.days', 7));
    $world->tenant->owner->switchTeam($world->tenant);
    $this->actingAs($world->tenant->owner)->get(route('brands.show', $world->brand))->assertOk()->assertInertia(fn ($page) => $page->where('analysisFilters.views', []));
    $this->deleteJson(route('brands.views.destroy', [$world->brand, $id]))->assertNotFound();
    $this->actingAs($reader)->postJson(route('brands.views.store', $world->brand), [...$payload, 'filters' => ['days' => 90]])->assertCreated()->assertJsonPath('id', $id);
    app(TenantContext::class)->set($world->tenant->id);
    expect(SavedView::query()->count())->toBe(1);
    $other = MetricsWorld::create();
    $this->actingAs($reader)->deleteJson(route('brands.views.destroy', [$other->brand, $id]))->assertNotFound();
    $this->deleteJson(route('brands.views.destroy', [$world->brand, $id]))->assertOk();
});

test('competitor suggestions honor engine and topic filters and stay within the brand', function () {
    $world = MetricsWorld::create();
    $topic = Topic::factory()->create(['brand_id' => $world->brand->id]);
    $world->p1->update(['topic_id' => $topic->id]);
    $otherEngine = Engine::factory()->create();
    MarketEngine::factory()->create(['market_id' => $world->market->id, 'engine_key' => $world->engine->key]);
    MarketEngine::factory()->create(['market_id' => $world->market->id, 'engine_key' => $otherEngine->key]);
    $otherPrompt = Prompt::factory()->create();
    foreach ([1, 2] as $ignored) {
        $world->answer($world->p1, now()->toIso8601String(), ['unknown' => 'Chosen fixture']);
        $world->answer($world->p2, now()->toIso8601String(), ['unknown' => 'Other topic']);
        $world->answer($world->p1, now()->toIso8601String(), ['unknown' => 'Other engine', 'engine' => $otherEngine]);
        $world->answer($otherPrompt, now()->toIso8601String(), ['unknown' => 'Other brand']);
    }
    $world->tenant->owner->switchTeam($world->tenant);
    $this->actingAs($world->tenant->owner)->get(route('brands.competitors.index', [$world->brand, 'engine' => $world->engine->key, 'topic' => $topic->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->where('suggestions', [['name' => 'Chosen fixture', 'responses' => 2]]));
});
