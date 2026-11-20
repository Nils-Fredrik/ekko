<?php

use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\AggDaily;
use App\Models\Competitor;
use App\Models\Engine;
use Tests\Support\Metrics\MetricsWorld;

test('graph series weight answers and include only active competitors with tied ranks', function () {
    $w = MetricsWorld::create();
    $inactive = Competitor::factory()->for($w->brand)->create(['is_active' => false]);
    $base = ['market_id' => $w->market->id, 'engine_key' => $w->engine->key, 'prompt_id' => $w->p1->id];
    AggDaily::factory()->create([...$base, 'date' => '2026-11-02', 'responses' => 2, 'brand_mentioned' => 1,
        'competitor_mentions' => [$w->c1->id => 1, $w->c2->id => 0, $inactive->id => 100]]);
    AggDaily::factory()->create([...$base, 'date' => '2026-11-03', 'responses' => 8, 'brand_mentioned' => 1,
        'competitor_mentions' => [$w->c1->id => 1, $w->c2->id => 4, $inactive->id => 100]]);
    $filter = new MetricsFilter($w->brand->id, '2026-11-02', '2026-11-08');
    $service = app(MetricsService::class);
    $weekly = $service->competitorVisibilitySeries($filter, 'week');
    $entities = collect($weekly[0]['entities'])->keyBy('id');

    expect($weekly)->toHaveCount(1)->and($weekly[0]['period'])->toBe('2026-11-02')
        ->and($entities['brand']['visibility'])->toBe(['value' => 0.2, 'n' => 10, 'low_sample' => false])
        ->and($entities['brand']['share_of_voice']['value'])->toBe(0.25)
        ->and($entities['brand']['rank'])->toBe(2)
        ->and($entities[(string) $w->c1->id]['rank'])->toBe(2)
        ->and($entities[(string) $w->c2->id]['rank'])->toBe(1)
        ->and($entities->has((string) $inactive->id))->toBeFalse();
    expect(fn () => $service->competitorVisibilitySeries($filter, 'year'))->toThrow(InvalidArgumentException::class);
});

test('heatmap, engine series and daily dots keep dimensions, samples and date limits', function () {
    $w = MetricsWorld::create();
    $otherEngine = Engine::factory()->create();
    $row = fn (string $date, string $engine, int $prompt, int $n, int $mentioned) => AggDaily::factory()->create([
        'market_id' => $w->market->id, 'date' => $date, 'engine_key' => $engine, 'prompt_id' => $prompt,
        'responses' => $n, 'brand_mentioned' => $mentioned, 'competitor_mentions' => [],
    ]);
    $row('2026-11-02', $w->engine->key, $w->p1->id, 4, 1);
    $row('2026-11-03', $w->engine->key, $w->p1->id, 6, 3);
    $row('2026-11-17', $otherEngine->key, $w->p2->id, 1, 0);
    $filter = new MetricsFilter($w->brand->id, '2026-11-01', '2026-11-17');
    $service = app(MetricsService::class);
    $cells = collect($service->visibilityByPromptEngine($filter))->keyBy('prompt_id');

    expect($cells[$w->p1->id]['visibility'])->toBe(['value' => 0.4, 'n' => 10, 'low_sample' => false])
        ->and($cells[$w->p2->id]['visibility'])->toBe(['value' => 0.0, 'n' => 1, 'low_sample' => true]);
    $daily = collect($service->visibilityByPromptSeries($filter))->where('prompt_id', $w->p1->id)->values();
    expect($daily)->toHaveCount(2)
        ->and($daily[0]['visibility'])->toBe(['value' => 0.25, 'n' => 4, 'low_sample' => true])
        ->and($daily[1]['visibility'])->toBe(['value' => 0.5, 'n' => 6, 'low_sample' => true]);
    $weekly = $service->visibilityByEngineSeries($filter, 'week');
    expect($weekly)->toHaveCount(2)
        ->and($weekly[0]['visibility'])->toBe(['value' => 0.4, 'n' => 10, 'low_sample' => false]);
    $dots = $service->stabilityDots($filter);
    expect($dots)->toHaveCount(1)->and($dots[0]['period'])->toBe('2026-11-17')
        ->and($dots[0]['value'])->toBe(0.0)->and($dots[0]['n'])->toBe(1);

    $limited = new MetricsFilter($w->brand->id, '2026-11-01', '2026-11-17', engineKeys: [$w->engine->key]);
    expect($service->visibilityByPromptEngine($limited))->toHaveCount(1)
        ->and($service->stabilityDots($limited))->toBe([]);
});

test('graph series cannot read another tenant even when a service is reused', function () {
    $a = MetricsWorld::create();
    AggDaily::factory()->create(['market_id' => $a->market->id, 'engine_key' => $a->engine->key,
        'prompt_id' => $a->p1->id, 'date' => '2026-11-02', 'responses' => 10, 'brand_mentioned' => 8]);
    $filter = new MetricsFilter($a->brand->id, '2026-11-01', '2026-11-10');
    $service = app(MetricsService::class);
    expect($service->visibilityByPromptEngine($filter))->toHaveCount(1);
    MetricsWorld::create();
    expect($service->visibilityByPromptEngine($filter))->toBe([])
        ->and($service->visibilityByEngineSeries($filter))->toBe([])
        ->and($service->stabilityDots($filter))->toBe([])
        ->and($service->competitorVisibilitySeries($filter))->toBe([]);
});
