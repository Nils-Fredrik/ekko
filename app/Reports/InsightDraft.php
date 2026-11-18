<?php

namespace App\Reports;

/** The Sonnet paragraph and its priced cost, ready to book once (ADR 0023). */
final class InsightDraft
{
    public function __construct(
        public readonly InsightAdmission $admission,
        public readonly string $text,
        public readonly string $cost,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {}
}
