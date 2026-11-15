<?php

namespace App\Jobs;

use App\Engines\EngineCallFailed;
use App\Engines\EngineCollector;
use App\Engines\EngineRegistry;
use App\Engines\QueuedEngineAdapter;
use App\Engines\QueuedEngineCollector;
use App\Jobs\Middleware\SetTenantContext;
use App\Models\Run;
use App\Scheduling\EngineThrottle;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use RuntimeException;
use Throwable;

final class CollectEngineRun extends TenantJob
{
    // Rate-limit releases do not consume the provider retry budget. Adapters own HTTP retries.
    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 420;

    public bool $failOnTimeout = true;

    public function __construct(int $tenantId, public readonly int $runId)
    {
        parent::__construct($tenantId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(EngineCollector $collector, QueuedEngineCollector $queued, EngineRegistry $registry, EngineThrottle $throttle, TenantRls $rls): void
    {
        $run = $rls->run([$this->tenantId], fn () => Run::query()->with('engine')->findOrFail($this->runId));
        if (! in_array($run->status, ['queued', 'running', 'submitted'], true)) {
            return;
        }
        $lease = $throttle->acquire($run->engine);
        if ($lease === null) {
            $this->release(15);

            return;
        }
        try {
            if ($registry->resolve($run->engine_key) instanceof QueuedEngineAdapter) {
                $queued->collect($run->id);
            } else {
                $collector->collect($run->id);
            }
        } catch (EngineCallFailed $failure) {
            if ($failure->getMessage() === 'run_already_reserved') {
                $this->release(30);

                return;
            }
            $this->fail(new RuntimeException($failure->getMessage()));
        } catch (Throwable) {
            // Provider exceptions can contain credentials and raw answers; never put them in failed_jobs.
            $this->fail(new RuntimeException('engine_collection_failed'));
        } finally {
            $lease->release();
        }
    }

    public function failed(?Throwable $failure): void
    {
        app(TenantContext::class)->run($this->tenantId, fn () => app(TenantRls::class)->run([$this->tenantId], function (): void {
            Run::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running', 'submitted'])->update([
                'status' => 'failed', 'error_code' => 'engine_collection_failed', 'finished_at' => now(),
                'enqueued_at' => null, 'provider_api_key' => null,
                'quota_reservation_token' => null, 'quota_reserved_at' => null,
                'quota_reserved_cost_usd' => null, 'quota_reserved_billed_to' => null,
            ]);
        }));
    }
}
