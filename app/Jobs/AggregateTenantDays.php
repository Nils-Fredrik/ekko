<?php

namespace App\Jobs;

use App\Metrics\DailyAggregator;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Aggregates the given tenant-local days for one tenant (docs/spec.md 9, ADR 0012). Idempotent, so a retry or a
 * second run for the same days gives the same rows. Every day is its own transaction scope only through the
 * job's row-level security transaction: a failure rolls the whole job back and it runs again from the start.
 *
 * Only one job per tenant and set of days is queued at a time.
 */
class AggregateTenantDays extends TenantJob implements ShouldBeUnique
{
    public int $uniqueFor = 3600;

    /**
     * @param  list<string>  $dates  Tenant-local days, Y-m-d.
     */
    public function __construct(int $tenantId, public readonly array $dates)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return 'aggregate:'.$this->tenantId.':'.implode(',', $this->dates);
    }

    public function handle(DailyAggregator $aggregator): void
    {
        foreach ($this->dates as $date) {
            $aggregator->aggregate($date);
        }
    }
}
