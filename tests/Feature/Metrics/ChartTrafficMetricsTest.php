<?php

use App\Metrics\ChartTrafficMetrics;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\AggDaily;
use App\Models\AiReferralDaily;
use App\Models\BotHitDaily;
use App\Models\Brand;
use App\Tenancy\TenantContext;
use Tests\Support\Metrics\MetricsWorld;

function trafficCharts(): object
{
    return new class
    {
        use ChartTrafficMetrics;
    };
}

test('robot and traffic charts sum by period and dimension without inventing missing days', function () {
    $world = MetricsWorld::create();
    $otherBrand = Brand::factory()->create();
    $brand = $world->brand->id;
    BotHitDaily::factory()->create(['brand_id' => $brand, 'domain' => 'one.example', 'bot' => 'GPTBot', 'date' => '2026-11-02', 'hits' => 8]);
    BotHitDaily::factory()->create(['brand_id' => $brand, 'domain' => 'two.example', 'bot' => 'GPTBot', 'date' => '2026-11-02', 'hits' => 4]);
    BotHitDaily::factory()->create(['brand_id' => $brand, 'bot' => 'ClaudeBot', 'date' => '2026-11-03', 'hits' => 3]);
    BotHitDaily::factory()->create(['brand_id' => $brand, 'bot' => 'GPTBot', 'date' => '2026-12-01', 'hits' => 90]);
    BotHitDaily::factory()->create(['brand_id' => $otherBrand->id, 'bot' => 'GPTBot', 'date' => '2026-11-02', 'hits' => 99]);
    AiReferralDaily::factory()->create(['brand_id' => $brand, 'source' => 'chatgpt.com', 'date' => '2026-11-02', 'sessions' => 6, 'engaged_sessions' => 3, 'conversions' => '1.5000']);
    AiReferralDaily::factory()->create(['brand_id' => $brand, 'source' => 'chatgpt.com', 'date' => '2026-11-03', 'sessions' => 5, 'engaged_sessions' => 2, 'conversions' => '0.5000']);
    AiReferralDaily::factory()->create(['brand_id' => $brand, 'source' => 'claude.ai', 'date' => '2026-11-03', 'sessions' => 2, 'engaged_sessions' => 1, 'conversions' => '0.0000']);
    $filter = new MetricsFilter($brand, '2026-11-02', '2026-11-30');

    expect(trafficCharts()->botHitsSeries($filter))->toBe([
        ['period' => '2026-11-02', 'bot' => 'GPTBot', 'hits' => 12, 'n' => 12, 'low_sample' => false],
        ['period' => '2026-11-03', 'bot' => 'ClaudeBot', 'hits' => 3, 'n' => 3, 'low_sample' => true],
    ])->and(trafficCharts()->aiTrafficSeries($filter, 'week'))->toBe([
        ['period' => '2026-11-02', 'source' => 'chatgpt.com', 'sessions' => 11, 'engaged_sessions' => 5, 'conversions' => 2.0, 'n' => 11, 'low_sample' => false],
        ['period' => '2026-11-02', 'source' => 'claude.ai', 'sessions' => 2, 'engaged_sessions' => 1, 'conversions' => 0.0, 'n' => 2, 'low_sample' => true],
    ])->and(trafficCharts()->botHitsSeries($filter, 'month')[0]['period'])->toBe('2026-11-01');
});

test('traffic charts respect tenant isolation and reject filters that their tables cannot represent', function () {
    $first = MetricsWorld::create();
    BotHitDaily::factory()->create(['brand_id' => $first->brand->id, 'date' => '2026-11-02']);
    AiReferralDaily::factory()->create(['brand_id' => $first->brand->id, 'date' => '2026-11-02']);
    $other = MetricsWorld::create();
    BotHitDaily::factory()->create(['brand_id' => $other->brand->id, 'date' => '2026-11-02']);
    AiReferralDaily::factory()->create(['brand_id' => $other->brand->id, 'date' => '2026-11-02']);
    $filter = new MetricsFilter($first->brand->id, '2026-11-01', '2026-11-30');

    expect(trafficCharts()->botHitsSeries($filter))->toBe([])
        ->and(trafficCharts()->aiTrafficSeries($filter))->toBe([]);
    app(TenantContext::class)->set($first->tenant->id);
    expect(trafficCharts()->botHitsSeries($filter))->toHaveCount(1)
        ->and(trafficCharts()->aiTrafficSeries($filter))->toHaveCount(1);

    foreach ([['marketIds' => [1]], ['engineKeys' => ['x']], ['topicIds' => [1]], ['promptIds' => [1]]] as $dimension) {
        $limited = new MetricsFilter(...['brandId' => $first->brand->id, 'from' => '2026-11-01', 'to' => '2026-11-30', ...$dimension]);
        expect(fn () => trafficCharts()->botHitsSeries($limited))->toThrow(InvalidArgumentException::class)
            ->and(fn () => trafficCharts()->aiTrafficSeries($limited))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => trafficCharts()->botHitsSeries($filter, 'year'))->toThrow(InvalidArgumentException::class);
});

test('funnel keeps the distinct units and sample sizes without inventing missing traffic', function () {
    $world = MetricsWorld::create();
    $filter = new MetricsFilter($world->brand->id, '2026-11-02', '2026-11-02');
    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'engine_key' => $world->engine->key,
        'prompt_id' => $world->p1->id, 'date' => '2026-11-02', 'responses' => 20, 'own_cited' => 4,
    ]);
    $metrics = app(MetricsService::class);
    expect($metrics->trafficFunnel($filter))->toBe([
        'fetched' => ['value' => null, 'n' => 0, 'low_sample' => true],
        'cited' => ['value' => 4, 'n' => 20, 'low_sample' => false],
        'visits' => ['value' => null, 'n' => 0, 'low_sample' => true],
    ]);
    BotHitDaily::factory()->create(['brand_id' => $world->brand->id, 'date' => '2026-11-02', 'hits' => 12]);
    AiReferralDaily::factory()->create(['brand_id' => $world->brand->id, 'date' => '2026-11-02', 'sessions' => 8]);
    expect($metrics->trafficFunnel($filter))->toBe([
        'fetched' => ['value' => 12, 'n' => 12, 'low_sample' => false],
        'cited' => ['value' => 4, 'n' => 20, 'low_sample' => false],
        'visits' => ['value' => 8, 'n' => 8, 'low_sample' => true],
    ]);
});
