<?php

namespace Tests\Support\Actions;

use App\ActionEngine\ActionCandidate;
use App\ActionEngine\ActionSource;
use App\ActionEngine\GenerationContext;
use App\ActionEngine\SourceOutcome;
use App\Enums\ActionType;

/**
 * A source that returns what the test sets: the scopes it judged and the candidates.
 */
final class FakeActionSource implements ActionSource
{
    /** @var list<int|null> */
    public array $evaluated = [];

    /** @var list<ActionCandidate> */
    public array $candidates = [];

    public function __construct(private readonly ActionType $type = ActionType::SourceGap) {}

    public function type(): ActionType
    {
        return $this->type;
    }

    public function evaluate(GenerationContext $context): SourceOutcome
    {
        return new SourceOutcome($this->evaluated, $this->candidates);
    }

    public function candidate(?int $marketId, string $subject, float $perMonth = 10, float $share = 0.5): ActionCandidate
    {
        return new ActionCandidate(
            type: $this->type,
            marketId: $marketId,
            subject: $subject,
            evidence: ['period' => ['from' => '2026-10-17', 'to' => '2026-11-15', 'days' => 30], 'domain' => $subject],
            affectedPerMonth: $perMonth,
            competitorOnlyShare: $share,
            textParams: ['domain' => $subject, 'gap_responses' => 3, 'days' => 30, 'competitor' => 'Nordlys', 'competitor_answers' => 2,
                'fact' => $subject, 'source' => $subject, 'prompts' => 2],
        );
    }
}
