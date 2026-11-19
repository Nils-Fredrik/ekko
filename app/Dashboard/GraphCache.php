<?php

namespace App\Dashboard;

use App\Metrics\MetricsFilter;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Cache already-authorized graph data. The caller must authorize access before
 * calling this class, including on a cache hit; cache rows do not have RLS.
 */
final class GraphCache
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Keep the TTL below the dashboard's two-minute freshness target even if
     * late-arriving data causes a historical aggregate to be rebuilt.
     *
     * @template TValue of array
     *
     * @param  Closure(): TValue  $calculate
     * @return TValue
     */
    public function remember(string $graph, MetricsFilter $filter, string $timezone, Closure $calculate): array
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $graph) !== 1) {
            throw new InvalidArgumentException('Invalid graph cache namespace.');
        }

        $tenantId = $this->tenant->require();
        $dimensions = [
            'graph' => $graph,
            'tenant' => $tenantId,
            'brand' => $filter->brandId,
            'from' => $filter->from,
            'to' => $filter->to,
            'market' => self::sorted($filter->marketIds),
            'engine' => self::sorted($filter->engineKeys),
            'topic' => self::sorted($filter->topicIds),
            'intent' => self::sorted($filter->intents),
            'funnel' => self::sorted($filter->funnelStages),
            'prompt' => self::sorted($filter->promptIds),
            'timezone' => $timezone,
            'day' => CarbonImmutable::now($timezone)->toDateString(),
        ];
        $key = 'graph:v1:'.hash('sha256', json_encode($dimensions, JSON_THROW_ON_ERROR));

        return Cache::remember($key, 60, $calculate);
    }

    /**
     * @template T of int|string
     *
     * @param  list<T>  $values
     * @return list<T>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
