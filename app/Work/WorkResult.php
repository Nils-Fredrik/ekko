<?php

namespace App\Work;

use Carbon\CarbonImmutable;

/**
 * What one processing attempt of a work signal came to (ADR 0032). busyUntil is set when the signal is pending
 * but held by a live lease or a backoff, so the delivery can come back then.
 */
final readonly class WorkResult
{
    public const PROCESSED = 'processed';

    public const DEFERRED = 'deferred';

    public const FAILED = 'failed';

    public const LEASE_LOST = 'lease_lost';

    public const BUSY = 'busy';

    public const IDLE = 'idle';

    public function __construct(public string $status, public ?CarbonImmutable $busyUntil = null) {}
}
