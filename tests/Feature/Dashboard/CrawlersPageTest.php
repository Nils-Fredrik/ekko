<?php

use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\AggDaily;
use App\Models\AiReferralDaily;
use App\Models\BotHitDaily;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Metrics\MetricsWorld;

// U09: robots and traffic (docs/ui-forbedring.md 7.6). Synthetic data only.

/** Seven days ending 2026-11-19 (Oslo): the period is 13–19 November and the previous period 6–12 November. */
function crawlersWorld(): MetricsWorld
{
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Europe/Oslo'));
    $world = MetricsWorld::create();
    $brand = $world->brand->id;
    foreach ([
        ['2026-11-18', 'oai_searchbot', 30, [['path' => '/guide', 'hits' => 20], ['path' => '/', 'hits' => 10]]],
        ['2026-11-18', 'gptbot', 5, [['path' => '/guide', 'hits' => 5]]],
        ['2026-11-19', 'oai_searchbot', 12, [['path' => '/', 'hits' => 12]]],
        ['2026-11-10', 'oai_searchbot', 7, [['path' => '/old', 'hits' => 7]]],
    ] as [$date, $bot, $hits, $paths]) {
        BotHitDaily::factory()->create([
            'brand_id' => $brand, 'date' => $date, 'bot' => $bot, 'hits' => $hits,
            'unique_paths' => count($paths), 'top_paths' => $paths,
        ]);
    }
    foreach ([['2026-11-18', 20, 4], ['2026-11-10', 10, 1]] as [$date, $responses, $cited]) {
        AggDaily::factory()->create([
            'market_id' => $world->market->id, 'engine_key' => $world->engine->key, 'prompt_id' => $world->p1->id,
            'date' => $date, 'responses' => $responses, 'own_cited' => $cited,
        ]);
    }
    foreach ([
        ['2026-11-18', 'chatgpt.com', 8],
        ['2026-11-19', 'perplexity.ai', 3],
        ['2026-11-11', 'chatgpt.com', 5],
    ] as [$date, $source, $sessions]) {
        AiReferralDaily::factory()->create([
            'brand_id' => $brand, 'date' => $date, 'source' => $source,
            'sessions' => $sessions, 'engaged_sessions' => 1, 'conversions' => '0.0000',
        ]);
    }
    $world->tenant->owner->switchTeam($world->tenant);
    Cache::flush();

    return $world;
}

test('the funnel shows the measured figures for the period and the comparison period', function () {
    $world = crawlersWorld();
    Integration::factory()->create(['brand_id' => $world->brand->id, 'type' => 'clickhouse_logs']);

    $this->actingAs($world->tenant->owner)->get(route('brands.crawlers', [$world->brand, 'days' => 7]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Brands/Crawlers')
            ->where('period.from', '2026-11-13')
            ->where('period.previous_to', '2026-11-12')
            ->where('period.grain', 'day')
            ->where('funnel.current', [
                'fetched' => ['value' => 47, 'n' => 47, 'low_sample' => false],
                'cited' => ['value' => 4, 'n' => 20, 'low_sample' => false],
                'visits' => ['value' => 11, 'n' => 11, 'low_sample' => false],
            ])
            ->where('funnel.previous', [
                'fetched' => ['value' => 7, 'n' => 7, 'low_sample' => true],
                'cited' => ['value' => 1, 'n' => 10, 'low_sample' => false],
                'visits' => ['value' => 5, 'n' => 5, 'low_sample' => true],
            ])
            ->where('connections', ['bot_logs' => true, 'ga4' => false])
            ->where('botLabels.oai_searchbot', 'OAI-SearchBot')
            ->where('botLabels.gptbot', 'GPTBot')
            ->where('bots.0', ['bot' => 'oai_searchbot', 'hits' => 42])
            ->missing('chartData')
            ->loadDeferredProps(fn ($deferred) => $deferred
                ->where('chartData.bots', [
                    ['period' => '2026-11-18', 'bot' => 'gptbot', 'hits' => 5, 'n' => 5, 'low_sample' => true],
                    ['period' => '2026-11-18', 'bot' => 'oai_searchbot', 'hits' => 30, 'n' => 30, 'low_sample' => false],
                    ['period' => '2026-11-19', 'bot' => 'oai_searchbot', 'hits' => 12, 'n' => 12, 'low_sample' => false],
                ])
                ->where('chartData.traffic.0.source', 'chatgpt.com')
                ->where('chartData.traffic.0.sessions', 8)
                ->has('chartData.traffic', 2)
                ->where('chartData.previous_traffic.0.period', '2026-11-11')
                ->where('chartData.previous_traffic.0.sessions', 5)
                ->where('chartData.pages', [
                    ['domain' => $world->brand->primary_domain, 'path' => '/guide', 'hits' => 25, 'bots' => 2],
                    ['domain' => $world->brand->primary_domain, 'path' => '/', 'hits' => 22, 'bots' => 1],
                ])));
});

test('unmeasured steps stay empty and say which integration is missing', function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Europe/Oslo'));
    $world = MetricsWorld::create();
    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'engine_key' => $world->engine->key, 'prompt_id' => $world->p1->id,
        'date' => '2026-11-18', 'responses' => 6, 'own_cited' => 2,
    ]);
    Integration::factory()->create(['brand_id' => $world->brand->id, 'type' => 'ga4']);
    $world->tenant->owner->switchTeam($world->tenant);
    Cache::flush();

    $this->actingAs($world->tenant->owner)->get(route('brands.crawlers', [$world->brand, 'days' => 7]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('funnel.current.fetched', ['value' => null, 'n' => 0, 'low_sample' => true])
            ->where('funnel.current.cited', ['value' => 2, 'n' => 6, 'low_sample' => true])
            ->where('funnel.current.visits', ['value' => null, 'n' => 0, 'low_sample' => true])
            ->where('connections', ['bot_logs' => false, 'ga4' => true])
            ->loadDeferredProps(fn ($deferred) => $deferred
                ->where('chartData.bots', [])
                ->where('chartData.traffic', [])
                ->where('chartData.pages', [])));
});

test('periods longer than a quarter are drawn per week', function () {
    $world = crawlersWorld();

    $this->actingAs($world->tenant->owner)
        ->get(route('brands.crawlers', [$world->brand, 'from' => '2026-07-01', 'to' => '2026-11-19']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('period.grain', 'week')
            ->loadDeferredProps(fn ($deferred) => $deferred
                ->where('chartData.bots.0.period', '2026-11-09')
                ->where('chartData.bots.0.hits', 7)
                ->where('chartData.bots.1.period', '2026-11-16')
                ->where('chartData.bots.1.bot', 'gptbot')));
});

test('the most fetched pages sum the daily top lists within the tenant, brand and period', function () {
    $world = crawlersWorld();
    $other = Team::factory()->tenant()->create();
    app(TenantContext::class)->run($other->id, function () {
        $brand = Brand::factory()->create();
        BotHitDaily::factory()->create([
            'brand_id' => $brand->id, 'date' => '2026-11-18', 'hits' => 999,
            'top_paths' => [['path' => '/guide', 'hits' => 999]],
        ]);
    });
    $sibling = Brand::factory()->create();
    BotHitDaily::factory()->create([
        'brand_id' => $sibling->id, 'date' => '2026-11-18', 'hits' => 500,
        'top_paths' => [['path' => '/guide', 'hits' => 500]],
    ]);
    app(TenantContext::class)->set($world->tenant->id);
    $metrics = app(MetricsService::class);

    expect($metrics->botTopPages(new MetricsFilter($world->brand->id, '2026-11-13', '2026-11-19'), 1))->toBe([
        ['domain' => $world->brand->primary_domain, 'path' => '/guide', 'hits' => 25, 'bots' => 2],
    ])->and(array_column($metrics->botTopPages(new MetricsFilter($world->brand->id, '2026-11-01', '2026-11-12')), 'path'))
        ->toBe(['/old'])
        ->and(fn () => $metrics->botTopPages(new MetricsFilter($world->brand->id, '2026-11-13', '2026-11-19', marketIds: [1])))
        ->toThrow(InvalidArgumentException::class);
});
