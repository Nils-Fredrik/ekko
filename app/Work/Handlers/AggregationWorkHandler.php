<?php

namespace App\Work\Handlers;

use App\Metrics\DailyAggregator;
use App\Work\WorkClaim;
use App\Work\WorkHandler;
use App\Work\WorkOutcome;

/**
 * Rebuilds the daily aggregates of one tenant-local day (aggregation:<Y-m-d>, T40-F3) with the same
 * {@see DailyAggregator} as ekko:aggregate, so the result equals a full recompute. Idempotent: it reads the stored
 * responses, mentions and citations of the day as they are now, also for a day outside the hourly lookback.
 */
final readonly class AggregationWorkHandler implements WorkHandler
{
    public function __construct(private DailyAggregator $aggregator) {}

    public function handle(WorkClaim $claim): WorkOutcome
    {
        $this->aggregator->aggregate($claim->scope);

        return WorkOutcome::done();
    }
}
