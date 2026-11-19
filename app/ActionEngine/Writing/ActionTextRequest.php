<?php

namespace App\ActionEngine\Writing;

/**
 * What the model gets to word one action: the system prompt, the user message with the evidence as JSON, the
 * output schema, and the texts the model may quote numbers from (names, never evidence figures).
 */
final readonly class ActionTextRequest
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $names
     */
    public function __construct(
        public string $system,
        public string $user,
        public array $schema,
        public array $names,
    ) {}
}
