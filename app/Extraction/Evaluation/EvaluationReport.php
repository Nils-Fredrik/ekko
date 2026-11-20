<?php

namespace App\Extraction\Evaluation;

/** Result of one evaluation run against the gold set. */
final class EvaluationReport
{
    /**
     * @param  array<string, int>  $responsesByLanguage
     * @param  array{recommended: Metric, position: Metric}  $model  the model's own output
     * @param  array{recommended: Metric, position: Metric}  $assembled  what is stored after the stages are combined
     * @param  array{correct: int, total: int}  $sourceTypes  source_type of the gold citations, from the model's output
     * @param  list<string>  $failedIds  responses where the batch result and the retry were both unusable
     * @param  array{correct: int, total: int}  $storedSourceTypes  source_type as stored, after the deterministic rules
     * @param  list<string>  $sourceTypeMisses  the gold citations whose stored source_type is wrong: id, URL, got ≠ expected
     */
    public function __construct(
        public readonly int $responses,
        public readonly array $responsesByLanguage,
        public readonly array $model,
        public readonly array $assembled,
        public readonly array $sourceTypes,
        public readonly int $retried,
        public readonly array $failedIds,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $costUsd,
        public readonly array $storedSourceTypes,
        public readonly array $sourceTypeMisses,
    ) {}
}
