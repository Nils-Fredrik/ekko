<?php

namespace App\ActionEngine\Sources;

use App\ActionEngine\ActionCandidate;
use App\ActionEngine\ActionSource;
use App\ActionEngine\GenerationContext;
use App\ActionEngine\SourceOutcome;
use App\Enums\ActionType;
use App\Metrics\Formulas;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;

/**
 * source_gap (docs/spec.md 9 and 11.3): domains cited in answers that name competitors but not the brand. One
 * action per domain and market, the largest gaps first.
 */
final readonly class SourceGapSource implements ActionSource
{
    public function __construct(private MetricsService $metrics) {}

    public function type(): ActionType
    {
        return ActionType::SourceGap;
    }

    public function evaluate(GenerationContext $context): SourceOutcome
    {
        $days = (int) config('ekko.actions.window_days');
        [$from, $to] = $context->window($days);
        $minimum = (int) config('ekko.actions.source_gap.min_gap_responses');
        $limit = (int) config('ekko.actions.source_gap.max_per_market');

        $evaluated = [];
        $candidates = [];
        foreach ($context->markets as $market) {
            $filter = new MetricsFilter($context->brand->id, $from, $to, marketIds: [$market->id]);
            $reach = $this->metrics->reach($filter);
            if ($reach['responses'] < (int) config('ekko.actions.min_answers')) {
                continue;
            }
            $evaluated[] = $market->id;

            $gaps = array_filter($this->metrics->sourceGap($filter, $limit), fn (array $gap): bool => $gap['gap_responses'] >= $minimum);
            foreach ($gaps as $gap) {
                $detail = $this->metrics->sourceGapDetail($filter, $gap['domain']);
                $top = $detail['competitors'][0] ?? null;
                $candidates[] = new ActionCandidate(
                    type: ActionType::SourceGap,
                    marketId: $market->id,
                    subject: $gap['domain'],
                    evidence: [
                        'period' => ['from' => $from, 'to' => $to, 'days' => $days],
                        'domain' => $gap['domain'],
                        'gap_responses' => $gap['gap_responses'],
                        'responses_with_domain' => $detail['responses_with_domain'],
                        'competitor_only_responses' => $gap['n'],
                        'responses' => $reach['responses'],
                    ],
                    affectedPerMonth: GenerationContext::perMonth($detail['responses_with_domain'], $days),
                    competitorOnlyShare: (float) (Formulas::ratio($gap['gap_responses'], $detail['responses_with_domain']) ?? 0.0),
                    competitorEvidence: array_map(fn (array $c): array => [
                        'competitor_id' => $c['competitor_id'], 'name' => $c['name'], 'answers' => $c['answers'], 'of' => $gap['gap_responses'],
                    ], $detail['competitors']),
                    prompts: $detail['prompts'],
                    engineKeys: $detail['engines'],
                    textParams: [
                        'domain' => $gap['domain'],
                        'gap_responses' => $gap['gap_responses'],
                        'days' => $days,
                        'competitor' => $top['name'] ?? '',
                        'competitor_answers' => $top['answers'] ?? 0,
                    ],
                );
            }
        }

        return new SourceOutcome($evaluated, $candidates);
    }
}
