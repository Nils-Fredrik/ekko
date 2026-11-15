<?php

namespace App\Extraction;

/**
 * What it took to extract one answer: the validated result (null when both the batch result and the single
 * retry were unusable) and every provider call made, for the usage log.
 */
final class ExtractionAttempt
{
    /**
     * @param  list<ExtractionOutcome>  $outcomes  the batch result and, when it was needed, the retry
     */
    public function __construct(
        public readonly string $customId,
        public readonly ?ExtractionResult $result,
        public readonly array $outcomes,
        public readonly bool $retried,
    ) {}

    public function failed(): bool
    {
        return $this->result === null;
    }
}
