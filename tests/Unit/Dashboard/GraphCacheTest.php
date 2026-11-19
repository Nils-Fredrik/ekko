<?php

use App\Dashboard\GraphCache;
use App\Metrics\MetricsFilter;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::clearResolvedInstances();
    Cache::flush();
    $this->context = app(TenantContext::class);
    $this->cache = app(GraphCache::class);
    $this->context->set(1);
    $this->travelTo(now()->setDate(2026, 11, 19)->setTime(12, 0));
});

afterEach(function (): void {
    $this->travelBack();
    $this->context->forget();
    Cache::clearResolvedInstances();
});

function graphFilter(array $overrides = []): MetricsFilter
{
    return new MetricsFilter(...array_merge([
        'brandId' => 42,
        'from' => '2026-10-01',
        'to' => '2026-11-19',
        'marketIds' => [3, 2],
        'engineKeys' => ['z', 'a'],
        'topicIds' => [4, 1],
        'intents' => ['z', 'a'],
        'funnelStages' => ['z', 'a'],
        'promptIds' => [9, 8],
    ], $overrides));
}

it('shares only equivalent normalized filters, graph names and the same tenant', function (): void {
    $calls = 0;
    $get = function (string $graph, MetricsFilter $filter) use (&$calls): array {
        return $this->cache->remember($graph, $filter, 'Europe/Oslo', function () use (&$calls): array {
            return ['value' => ++$calls];
        });
    };
    expect($get('trend', graphFilter()))->toBe(['value' => 1]);
    expect($get('trend', graphFilter(['marketIds' => [2, 3, 2]])))->toBe(['value' => 1]);
    foreach ([
        ['brandId' => 43], ['from' => '2026-10-02'], ['to' => '2026-11-18'],
        ['marketIds' => [2]], ['engineKeys' => ['a']], ['topicIds' => [1]],
        ['intents' => ['a']], ['funnelStages' => ['a']], ['promptIds' => [8]],
    ] as $overrides) {
        $get('trend', graphFilter($overrides));
    }
    $get('other', graphFilter());
    $this->context->set(2);
    $get('trend', graphFilter());
    expect($calls)->toBe(12);
});

it('does not use cache without a tenant or an invalid namespace', function (): void {
    $this->context->forget();
    expect(fn () => $this->cache->remember('trend', graphFilter(), 'Europe/Oslo', fn () => []))
        ->toThrow(MissingTenantContextException::class);
    $this->context->set(1);
    expect(fn () => $this->cache->remember('bad:name', graphFilter(), 'Europe/Oslo', fn () => []))
        ->toThrow(InvalidArgumentException::class);
});

it('expires stale values after one minute and separates local day and timezone', function (): void {
    $calls = 0;
    $get = function (string $timezone) use (&$calls): array {
        return $this->cache->remember('trend', graphFilter(), $timezone, function () use (&$calls): array {
            return ['value' => ++$calls];
        });
    };
    expect($get('Europe/Oslo'))->toBe(['value' => 1]);
    $this->travel(59)->seconds();
    expect($get('Europe/Oslo'))->toBe(['value' => 1]);
    $this->travel(2)->seconds();
    expect($get('Europe/Oslo'))->toBe(['value' => 2]);
    expect($get('UTC'))->toBe(['value' => 3]);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 23:59:59', 'Europe/Oslo'));
    $get('Europe/Oslo');
    $before = $calls;
    $this->travel(2)->seconds();
    $get('Europe/Oslo');
    expect($calls)->toBe($before + 1);
});
