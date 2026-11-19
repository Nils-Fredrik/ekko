<?php

namespace App\Work;

use App\Jobs\ProcessWorkSignal;

/**
 * Wakes the processor through the Laravel queue (ADR 0032). The job is unique per wake-up key until it starts,
 * so coalesced signals queue one job.
 */
final class QueueWorkPublisher implements WorkPublisher
{
    public function publish(WorkWakeUp $wakeUp): void
    {
        $job = new ProcessWorkSignal($wakeUp->tenantId, $wakeUp->signalId, $wakeUp->key());
        if ($wakeUp->notBefore->isFuture()) {
            $job->delay($wakeUp->notBefore);
        }

        dispatch($job);
    }
}
