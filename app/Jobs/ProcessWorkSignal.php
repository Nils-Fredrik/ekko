<?php

namespace App\Jobs;

use App\Jobs\Middleware\SetTenantContext;
use App\Work\WorkProcessor;
use App\Work\WorkResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * Delivers one wake-up of a durable work signal (docs/spec.md 7.1, ADR 0032). The signal row is the source of
 * truth: a duplicate or late delivery finds nothing to claim and ends. A signal held by a live lease or a backoff
 * releases the job until then. Failures are retried through the signal's backoff, not through queue retries.
 */
final class ProcessWorkSignal extends TenantJob implements ShouldBeUniqueUntilProcessing
{
    /** Each release while the signal is busy is an attempt; the recovery sweep covers what is left after them. */
    public int $tries = 5;

    /** Shorter than ekko.work.lease_seconds, so a lease never expires under a job that is still running. */
    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(int $tenantId, public readonly int $signalId, public readonly string $wakeKey)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return 'work:'.$this->wakeKey;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // ADR 0032: the claim must commit before the handler runs, so the processor opens its own short RLS
        // transactions; an outer ApplyTenantRls transaction would hide the lease until the end.
        return [new SetTenantContext];
    }

    public function handle(WorkProcessor $processor): void
    {
        $result = $processor->process($this->signalId);

        if ($result->status === WorkResult::BUSY && $result->busyUntil !== null) {
            $this->release(max(1, (int) ceil(CarbonImmutable::now()->diffInSeconds($result->busyUntil, false)) + 1));
        }
    }
}
