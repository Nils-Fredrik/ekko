<?php

namespace App\Work;

/**
 * Delivers wake-ups for durable work (ADR 0032). Called only after the registering transaction has committed.
 *
 * An implementation must be idempotent for the same key() and may throw: the signal is already durable, so a
 * failed publish costs latency, never work. The recovery sweep publishes again. T40-F2 has a queue publisher;
 * T40-F4 adds Cloud Tasks with key() as task id, payload() as body and notBefore as schedule time.
 */
interface WorkPublisher
{
    public function publish(WorkWakeUp $wakeUp): void;
}
