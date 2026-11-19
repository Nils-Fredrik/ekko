<?php

namespace App\Work;

use Carbon\CarbonImmutable;

/**
 * What a handler did with a claim (ADR 0032): done acknowledges the leased version; retryAt keeps the signal
 * pending without counting a failure and wakes it again at the given time (an external batch still running).
 * A failure is an exception.
 */
final readonly class WorkOutcome
{
    private function __construct(public ?CarbonImmutable $retryAt) {}

    public static function done(): self
    {
        return new self(null);
    }

    public static function retryAt(CarbonImmutable $at): self
    {
        return new self($at);
    }

    public function isDone(): bool
    {
        return $this->retryAt === null;
    }
}
