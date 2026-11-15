<?php

namespace App\Engines;

use Carbon\CarbonImmutable;

final class QueuedEngineResubmission
{
    /**
     * The caller holds the run lock, confirms that the task is still pending,
     * and records the replacement task ID and submission count together.
     */
    public function resubmitPending(
        QueuedEngineAdapter $adapter,
        EngineRequest $request,
        CarbonImmutable $submittedAt,
        int $submissionCount,
    ): ?string {
        if ($submissionCount !== 1 || CarbonImmutable::now('UTC')->lt($submittedAt->utc()->addHours(6))) {
            return null;
        }

        return $adapter->submit($request);
    }
}
