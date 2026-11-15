<?php

namespace App\Extraction;

/** The combined result of both extraction stages for one answer. */
final class AssembledExtraction
{
    /**
     * @param  list<AssembledMention>  $mentions
     * @param  list<ModelCitation>  $citations  what the model found in the text; the writer decides which of them are stored
     * @param  string|null  $answerType  null when the model stage failed
     */
    public function __construct(
        public readonly array $mentions,
        public readonly array $citations,
        public readonly ?string $answerType,
        public readonly bool $llmFailed,
    ) {}
}
