<?php

namespace App\Jobs;

use App\FactCheck\FactChecker;
use App\Jobs\Middleware\SetTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * Judges the extracted answers of one tenant against the facts of their brand (docs/spec.md 11.6, ADR 0036).
 * Dispatched after an extraction has been written for a brand with facts. Like GenerateTenantActions it sets the
 * tenant context only: the model calls run outside any transaction and the checker opens short row-level security
 * transactions itself. At most ekko.facts.max_checks_per_run answers per run, no call started after
 * ekko.facts.check_seconds or so late that it could outlive the job, and the job queues itself again when more wait.
 * A paid call is never repeated by a retry.
 */
class CheckTenantFacts extends TenantJob implements ShouldBeUniqueUntilProcessing
{
    public int $tries = 1;

    /** As the worker's --timeout (infra/terraform/workloads.tf); the deadline is derived from it. */
    public int $timeout = 420;

    public int $uniqueFor = 3600;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function uniqueId(): string
    {
        return 'fact-check:'.$this->tenantId;
    }

    public function handle(FactChecker $checker): void
    {
        $callSeconds = (int) config('ekko.anthropic.factcheck.timeout_seconds');
        $deadline = CarbonImmutable::now()->getTimestamp() + max(0, min((int) config('ekko.facts.check_seconds'), $this->timeout - $callSeconds - 30));

        if ($checker->run($this->tenantId, $deadline)) {
            self::dispatch($this->tenantId)->afterCommit();
        }
    }
}
