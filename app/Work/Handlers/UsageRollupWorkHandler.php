<?php

namespace App\Work\Handlers;

use App\Usage\UsageRollup;
use App\Work\WorkClaim;
use App\Work\WorkHandler;
use App\Work\WorkOutcome;
use Carbon\CarbonImmutable;

/**
 * Rolls up one UTC month of usage (usage_rollup:<Y-m>, T40-F3) with the same {@see UsageRollup} as
 * ekko:usage-rollup: the totals are recomputed from usage_events, never added to, and the quota alert is created only
 * when the stored threshold rises, under the tenant row lock. So neither usage nor alerts are counted twice, however
 * often the month is signalled or the hourly roll-up runs alongside.
 */
final readonly class UsageRollupWorkHandler implements WorkHandler
{
    public function __construct(private UsageRollup $rollup) {}

    public function handle(WorkClaim $claim): WorkOutcome
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $claim->scope, 'UTC');
        if ($month !== null) {
            $this->rollup->refreshMonth($month, CarbonImmutable::now('UTC'));
        }

        return WorkOutcome::done();
    }
}
