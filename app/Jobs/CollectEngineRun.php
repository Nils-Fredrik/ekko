<?php

namespace App\Jobs;

use App\Engines\EngineCollector;
use App\Jobs\Middleware\SetTenantContext;

final class CollectEngineRun extends TenantJob
{
    public function __construct(int $tenantId, public readonly int $runId)
    {
        parent::__construct($tenantId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // The collector scopes each database phase separately so the external call runs
        // after the admission transaction has released the tenant lock.
        return [new SetTenantContext];
    }

    public function handle(EngineCollector $collector): void
    {
        $collector->collect($this->runId);
    }
}
