<?php

namespace App\ActionEngine\Writing;

/**
 * A model reply with a known outcome: billed whether or not the text is usable. text is null when the model
 * refused or ran out of tokens (failure says which).
 */
final readonly class ActionWriterResult
{
    public function __construct(
        public ?string $text,
        public int $inputTokens,
        public int $outputTokens,
        public ?string $failure = null,
    ) {}
}
