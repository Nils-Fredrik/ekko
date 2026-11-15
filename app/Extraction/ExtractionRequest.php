<?php

namespace App\Extraction;

/**
 * One answer to analyse. The system prompt and user message are built by {@see ExtractionPromptBuilder}
 * and contain the answer text, so a request is never logged.
 */
final class ExtractionRequest
{
    public function __construct(
        public readonly string $customId,
        public readonly string $system,
        public readonly string $user,
    ) {}
}
