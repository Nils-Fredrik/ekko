<?php

namespace App\FactCheck\Evaluation;

use App\Extraction\Evaluation\Metric;

/**
 * The result of evaluating the fact check against the gold set. verdicts holds precision and hit rate per
 * verdict (supported, contradicted): "positive" is a claim with that verdict on a fact, and a prediction is correct
 * only when the gold set expects the same verdict on the same fact in the same answer.
 */
final class FactCheckReport
{
    /**
     * @param  array<string, int>  $casesByLanguage
     * @param  array<string, Metric>  $verdicts  by verdict
     * @param  array<string, array{correct: int, total: int}>  $accuracyByLanguage
     * @param  list<string>  $failedIds  answers without a usable model result
     */
    public function __construct(
        public readonly int $cases,
        public readonly int $expectedClaims,
        public readonly array $casesByLanguage,
        public readonly array $verdicts,
        public readonly int $correct,
        public readonly array $accuracyByLanguage,
        public readonly int $unexpectedClaims,
        public readonly array $failedIds,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $costUsd,
    ) {}

    /** Share of the expected verdicts the model got right, null without any. */
    public function accuracy(): ?float
    {
        return $this->expectedClaims === 0 ? null : $this->correct / $this->expectedClaims;
    }
}
