<?php

namespace App\Engines;

use InvalidArgumentException;

final readonly class EngineResult
{
    /**
     * @param  list<array{url: string, title?: string|null, rank?: int, from_annotation?: bool}>  $citations
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public string $answerText,
        public ?string $modelIdReported,
        public array $citations,
        public int $inputTokens,
        public int $outputTokens,
        public int $searchCalls,
        public array $raw,
    ) {
        if (min($inputTokens, $outputTokens, $searchCalls) < 0) {
            throw new InvalidArgumentException('Engine usage counters cannot be negative.');
        }
    }
}
