<?php

namespace App\ActionEngine\Writing;

use RuntimeException;

/**
 * The model call failed. known: the provider answered with an error and nothing was billed (the reservation is
 * released). Otherwise the outcome is unknown (timeout, broken connection) and the reservation is kept (ADR 0023).
 * creditExhausted: the provider said the credit balance is too low.
 */
final class ActionWriterFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $known = false,
        public readonly bool $creditExhausted = false,
    ) {
        parent::__construct($message);
    }
}
