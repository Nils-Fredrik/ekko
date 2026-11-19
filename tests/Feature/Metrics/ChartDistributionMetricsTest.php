<?php

use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\Engine;
use App\Tenancy\TenantContext;
use Tests\Support\Metrics\MetricsWorld;

function chartDistributionFilter(MetricsWorld $w, array $engines = []): MetricsFilter
{
    return new MetricsFilter($w->brand->id, '2026-11-01', '2026-11-30', engineKeys: $engines);
}

function chartDistributionDaily(MetricsWorld $w, string $date, ?array $distribution, ?string $engine = null): AggDaily
{
    return AggDaily::factory()->create([
        'market_id' => $w->market->id, 'brand_id' => $w->brand->id, 'prompt_id' => $w->p1->id,
        'engine_key' => $engine ?? $w->engine->key, 'date' => $date, 'sentiment_distribution' => $distribution,
    ]);
}

function chartDistributionSource(MetricsWorld $w, string $date, ?array $distribution, ?string $engine = null): AggSourceDaily
{
    return AggSourceDaily::factory()->create([
        'market_id' => $w->market->id, 'brand_id' => $w->brand->id, 'domain' => 'review.example',
        'engine_key' => $engine ?? $w->engine->key, 'date' => $date, 'citation_distribution' => $distribution,
    ]);
}

test('sentiment charts sum observed mentions for each active entity with n', function () {
    $w = MetricsWorld::create();
    $w->c2->update(['is_active' => false]);
    chartDistributionDaily($w, '2026-11-10', [
        'own' => ['negative' => 1, 'neutral' => 2, 'positive' => 3],
        'competitors' => [$w->c1->id => ['negative' => 2, 'neutral' => 1, 'positive' => 0], $w->c2->id => ['negative' => 8, 'neutral' => 0, 'positive' => 0]],
    ]);
    chartDistributionDaily($w, '2026-11-11', [
        'own' => ['negative' => 0, 'neutral' => 2, 'positive' => 2],
        'competitors' => [$w->c1->id => ['negative' => 0, 'neutral' => 0, 'positive' => 1]],
    ]);
    $result = app(MetricsService::class)->sentimentDistribution(chartDistributionFilter($w));
    expect($result['complete'])->toBeTrue()
        ->and(collect($result['rows'])->keyBy('key')->all())->toEqual([
            'own' => ['key' => 'own', 'negative' => 1, 'neutral' => 4, 'positive' => 5, 'n' => 10, 'low_sample' => false],
            'competitor:'.$w->c1->id => ['key' => 'competitor:'.$w->c1->id, 'negative' => 2, 'neutral' => 1, 'positive' => 1, 'n' => 4, 'low_sample' => true],
        ]);
});

test('unknown historical sentiment suppresses partial output but only within selected filters', function () {
    $w = MetricsWorld::create();
    $other = Engine::factory()->create();
    chartDistributionDaily($w, '2026-11-10', ['own' => ['negative' => 0, 'neutral' => 0, 'positive' => 1], 'competitors' => (object) []]);
    chartDistributionDaily($w, '2026-11-10', null, $other->key);
    $metrics = app(MetricsService::class);
    expect($metrics->sentimentDistribution(chartDistributionFilter($w)))->toBe(['complete' => false, 'rows' => null]);
    $filtered = $metrics->sentimentDistribution(chartDistributionFilter($w, [$w->engine->key]));
    expect($filtered['complete'])->toBeTrue()->and($filtered['rows'][0]['positive'])->toBe(1);
});

test('citation distribution keeps mixed categories and does not report partial history', function () {
    $w = MetricsWorld::create();
    $other = Engine::factory()->create();
    chartDistributionSource($w, '2026-11-10', ['own' => ['news' => 2], 'third_party' => ['review' => 3, 'unknown' => 1]]);
    chartDistributionSource($w, '2026-11-11', ['third_party' => ['review' => 4]]);
    $metrics = app(MetricsService::class);
    $filter = chartDistributionFilter($w);
    $rows = collect($metrics->citationDistribution($filter)['rows'])->keyBy(fn ($row) => $row['ownership'].':'.$row['source_type']);
    expect($rows['third_party:review'])->toEqual(['domain' => 'review.example', 'ownership' => 'third_party', 'source_type' => 'review', 'citations' => 7, 'n' => 10, 'low_sample' => false])
        ->and($rows['third_party:unknown']['citations'])->toBe(1)
        ->and($rows['own:news']['citations'])->toBe(2);
    chartDistributionSource($w, '2026-11-10', null, $other->key);
    expect($metrics->citationDistribution($filter))->toBe(['complete' => false, 'rows' => null])
        ->and($metrics->citationDistribution(chartDistributionFilter($w, [$w->engine->key]))['complete'])->toBeTrue();
    expect(fn () => $metrics->citationDistribution(new MetricsFilter($w->brand->id, '2026-11-01', '2026-11-30', topicIds: [1])))
        ->toThrow(InvalidArgumentException::class);
});

test('both distributions are confined to the tenant even when a foreign brand id is supplied', function () {
    $one = MetricsWorld::create();
    chartDistributionDaily($one, '2026-11-10', ['own' => ['negative' => 0, 'neutral' => 0, 'positive' => 4], 'competitors' => (object) []]);
    chartDistributionSource($one, '2026-11-10', ['own' => ['news' => 4]]);
    $two = MetricsWorld::create();
    chartDistributionDaily($two, '2026-11-10', ['own' => ['negative' => 8, 'neutral' => 0, 'positive' => 0], 'competitors' => (object) []]);
    chartDistributionSource($two, '2026-11-10', ['third_party' => ['review' => 8]]);
    app(TenantContext::class)->set($one->tenant->id);

    $metrics = app(MetricsService::class);
    expect($metrics->sentimentDistribution(chartDistributionFilter($one))['rows'][0]['positive'])->toBe(4)
        ->and($metrics->citationDistribution(chartDistributionFilter($one))['rows'][0]['citations'])->toBe(4)
        ->and($metrics->sentimentDistribution(chartDistributionFilter($two))['rows'][0]['n'])->toBe(0)
        ->and($metrics->citationDistribution(chartDistributionFilter($two))['rows'])->toBe([]);
});
