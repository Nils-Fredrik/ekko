<?php

namespace App\FactCheck\Judge;

/**
 * A model reply with a known outcome: billed whether or not the text is usable. text is null when the model refused
 * or ran out of tokens (failure says which).
 */
final class FactJudgeResult
{
    public function __construct(
        public readonly ?string $text,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly ?string $failure = null,
        /** The Anthropic budget operation the call was reserved under; set by FactChecker. */
        public ?string $operation = null,
    ) {}
}
