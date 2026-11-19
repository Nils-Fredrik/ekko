<?php

namespace App\FactCheck\Judge;

use RuntimeException;

/**
 * The model call failed. known: the provider answered with an error and nothing was billed (the reservation is
 * released). Otherwise the outcome is unknown (timeout, broken connection) and the reservation is kept (ADR 0023).
 * creditExhausted: the provider said the credit balance is too low. accountWide: the error says nothing about this
 * answer but about the account or the service (401, 403, 429, credit), so there is no point in calling again now; an
 * error about the request itself (400, 413, 422) is only that answer's.
 */
final class FactJudgeFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $known = false,
        public readonly bool $creditExhausted = false,
        public readonly bool $accountWide = false,
    ) {
        parent::__construct($message);
    }
}
