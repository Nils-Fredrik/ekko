<?php

namespace App\FactCheck;

/**
 * One answer to judge. The system prompt and user message are built by {@see FactCheckPromptBuilder} and contain
 * the answer text and the customer's facts, so a request is never logged.
 */
final readonly class FactCheckRequest
{
    /** @param  array<string, mixed>  $schema */
    public function __construct(
        public string $customId,
        public string $system,
        public string $user,
        public array $schema,
    ) {}
}
