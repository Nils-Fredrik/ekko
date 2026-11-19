<?php

namespace App\ActionEngine;

use App\Enums\ActionType;

/**
 * One action a source finds in the data (docs/spec.md 11.3). Every number in evidence and competitorEvidence is
 * read from the database by the source; the model never writes them. The subject identifies the finding within
 * the type, brand and market (a domain, a topic, a name, a bot), so the fingerprint recognises it next week.
 *
 * Expected effect: affectedPerMonth (answers per month the action concerns) × competitorOnlyShare (the share of
 * those answers that name a competitor and not the brand). textParams fill the template texts and the evidence
 * sentence (lang action_items).
 */
final readonly class ActionCandidate
{
    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<array<string, mixed>>  $competitorEvidence
     * @param  array<int, int>  $prompts  prompt id => answers
     * @param  list<string>  $engineKeys
     * @param  array<string, string|int|float>  $textParams
     */
    public function __construct(
        public ActionType $type,
        public ?int $marketId,
        public string $subject,
        public array $evidence,
        public float $affectedPerMonth,
        public float $competitorOnlyShare,
        public array $competitorEvidence = [],
        public array $prompts = [],
        public array $engineKeys = [],
        public ?int $topicId = null,
        public array $textParams = [],
    ) {}

    public function fingerprint(int $brandId): string
    {
        return self::fingerprintOf($this->type, $brandId, $this->marketId, $this->subject);
    }

    public static function fingerprintOf(ActionType $type, int $brandId, ?int $marketId, string $subject): string
    {
        return hash('sha256', implode('|', [$type->value, $brandId, $marketId ?? '-', mb_strtolower(trim($subject))]));
    }

    public function impactScore(): float
    {
        return round(max(0.0, $this->affectedPerMonth) * min(1.0, max(0.0, $this->competitorOnlyShare)), 2);
    }
}
