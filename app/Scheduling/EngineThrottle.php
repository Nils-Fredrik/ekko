<?php

namespace App\Scheduling;

use App\Models\Engine;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class EngineThrottle
{
    public function acquire(Engine $engine): ?Lock
    {
        $cache = Cache::store('database');
        $slots = max(1, min(64, (int) data_get($engine->config, 'concurrency', config('collection.concurrency'))));
        $limit = max(1, (int) data_get($engine->config, 'calls_per_minute', config('collection.calls_per_minute')));
        for ($i = 0; $i < $slots; $i++) {
            $lease = CollectionLocks::make('engine:'.$engine->key.':slot:'.$i, 450);
            if (! $lease->get()) {
                continue;
            }
            $guard = CollectionLocks::make('engine:'.$engine->key.':rate-guard', 10);
            if (! $guard->get()) {
                $lease->release();

                return null;
            }
            try {
                // Sliding window, shared by all processes and tenants. Each adapter operation
                // can retry twice; the configured limit counts admitted collection operations.
                $key = 'engine:'.$engine->key.':rate';
                $timestamps = array_values(array_filter((array) $cache->get($key, []), fn ($at) => $at > microtime(true) - 60));
                if (count($timestamps) >= $limit) {
                    $lease->release();

                    return null;
                }
                $timestamps[] = microtime(true);
                $cache->put($key, $timestamps, 60);

                return $lease;
            } finally {
                $guard->release();
            }
        }

        return null;
    }
}
