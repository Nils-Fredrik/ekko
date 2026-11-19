<?php

namespace App\Work\CloudTasks;

use App\Work\WorkPublisher;
use App\Work\WorkWakeUp;
use Carbon\CarbonImmutable;

/**
 * Publishes wake-ups as Cloud Tasks (T40-F4, ADR 0033): one HTTP task per wake-up key, scheduled at notBefore, to
 * the fixed route /internal/work/wake with {tenant_id, signal_id} as body. Coalesced signals share a key and so a
 * task; a repeated publish is refused as ALREADY_EXISTS and counts as published.
 *
 * Queue wake-ups (queueWake) go to /internal/work/queue-wake without a body of ids: a worker that stops while queue
 * jobs wait for a later time (a release or a delay) asks to be started again then, instead of a fixed poll.
 */
class CloudTasksWorkPublisher implements WorkPublisher
{
    public function __construct(private readonly CloudTasksClient $client) {}

    public function publish(WorkWakeUp $wakeUp): void
    {
        $this->client->create($wakeUp->taskId(), '/internal/work/wake', $wakeUp->payload(), $wakeUp->notBefore);
    }

    /** A wake-up for the queue at $at, rounded up to the second: every stopping worker process asks for the same one. */
    public function queueWake(CarbonImmutable $at): bool
    {
        $second = $at->utc()->ceilSecond();

        return $this->client->create(WorkWakeUp::hashedTaskId('qw-'.$second->getTimestamp()), '/internal/work/queue-wake', ['queue' => 'default'], $second);
    }
}
