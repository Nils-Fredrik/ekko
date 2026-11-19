<?php

namespace App\Work;

/**
 * Publishes nothing (ADR 0032): the signals stay durable and only the recovery sweep or a later publisher wakes
 * them. For environments where event-driven processing is not switched on.
 */
final class NullWorkPublisher implements WorkPublisher
{
    public function publish(WorkWakeUp $wakeUp): void {}
}
