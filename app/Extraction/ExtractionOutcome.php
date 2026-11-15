<?php

namespace App\Extraction;

/** What the provider returned for one request: the model's text, or the reason there is none. */
final class ExtractionOutcome
{
    public function __construct(
        public readonly string $customId,
        public readonly bool $succeeded,
        public readonly ?string $text,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly bool $viaBatch,
        public readonly ?string $errorType = null,
    ) {}
}
