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
use App\Models\Prompt;
use App\Models\Topic;

/**
 * owned_content (docs/spec.md 11.3): prompts with low visibility where the brand's own pages are never cited.
 * One action per topic and market, when at least ekko.actions.owned_content.min_prompts prompts of the topic
 * qualify (prompts without a topic are left out). A prompt qualifies with enough answers, visibility below the threshold and a citation rate of 0.
 */
final readonly class OwnedContentSource implements ActionSource
{
    public function __construct(private MetricsService $metrics) {}

    public function type(): ActionType
    {
        return ActionType::OwnedContent;
    }

    public function evaluate(GenerationContext $context): SourceOutcome
    {
        $days = (int) config('ekko.actions.window_days');
        [$from, $to] = $context->window($days);
        $minAnswers = (int) config('ekko.actions.owned_content.min_prompt_answers');
        $maxVisibility = (float) config('ekko.actions.owned_content.max_visibility');
        $minPrompts = (int) config('ekko.actions.owned_content.min_prompts');

        $evaluated = [];
        $candidates = [];
        foreach ($context->markets as $market) {
            $filter = new MetricsFilter($context->brand->id, $from, $to, marketIds: [$market->id]);
            if ($this->metrics->reach($filter)['responses'] < (int) config('ekko.actions.min_answers')) {
                continue;
            }
            $evaluated[] = $market->id;

            $perPrompt = $this->metrics->breakdown($filter, 'prompt');
            /** @var array<int, int|null> $topics prompt id => topic id */
            $topics = Prompt::query()->where('market_id', $market->id)->pluck('topic_id', 'id')->all();

            /** @var array<string, array{total: int, low: list<int>, answers: array<int, int>}> $byTopic */
            $byTopic = [];
            foreach ($perPrompt as $promptId => $summary) {
                // Prompts without a topic are left out: the action is about a topic.
                if ($summary->responses < $minAnswers || ($topics[(int) $promptId] ?? null) === null) {
                    continue;
                }
                $key = (string) $topics[(int) $promptId];
                $byTopic[$key] ??= ['total' => 0, 'low' => [], 'answers' => []];
                $byTopic[$key]['total']++;
                if (($summary->visibility->value ?? 0.0) < $maxVisibility && ($summary->citationRate->value ?? 0.0) === 0.0) {
                    $byTopic[$key]['low'][] = (int) $promptId;
                    $byTopic[$key]['answers'][(int) $promptId] = $summary->responses;
                }
            }
            ksort($byTopic, SORT_STRING);

            foreach ($byTopic as $topicKey => $group) {
                if (count($group['low']) < $minPrompts) {
                    continue;
                }
                $topic = Topic::query()->find((int) $topicKey);
                if ($topic === null) {
                    continue;
                }
                $lowFilter = new MetricsFilter($context->brand->id, $from, $to, marketIds: [$market->id], promptIds: $group['low']);
                $reach = $this->metrics->reach($lowFilter);
                $summary = $this->metrics->summary($lowFilter);
                $prompts = $group['answers'];
                arsort($prompts);

                $competitors = array_slice(array_map(fn (array $row): array => [
                    'competitor_id' => $row['competitor_id'],
                    'name' => $row['name'],
                    'answers' => $row['mentions'],
                    'of' => $reach['responses'],
                ], array_filter($this->metrics->competitorComparison($lowFilter), fn (array $row): bool => $row['mentions'] > 0)), 0, 5);

                $candidates[] = new ActionCandidate(
                    type: ActionType::OwnedContent,
                    marketId: $market->id,
                    subject: 'topic:'.$topic->id,
                    evidence: [
                        'period' => ['from' => $from, 'to' => $to, 'days' => $days],
                        'topic' => $topic->name,
                        'prompts_total' => $group['total'],
                        'prompts_without_own_citation' => count($group['low']),
                        'responses' => $reach['responses'],
                        'competitor_only_responses' => $reach['competitor_only_responses'],
                        'visibility' => $summary->visibility->toArray(),
                        'max_visibility' => $maxVisibility,
                    ],
                    affectedPerMonth: GenerationContext::perMonth($reach['responses'], $days),
                    competitorOnlyShare: (float) (Formulas::ratio($reach['competitor_only_responses'], $reach['responses']) ?? 0.0),
                    competitorEvidence: $competitors,
                    prompts: $prompts,
                    engineKeys: array_map('strval', array_keys($this->metrics->breakdown($lowFilter, 'engine'))),
                    topicId: $topic->id,
                    textParams: [
                        'topic' => $topic->name,
                        'prompts_without' => count($group['low']),
                        'prompts_total' => $group['total'],
                        'visibility' => (int) round(($summary->visibility->value ?? 0.0) * 100),
                    ],
                );
            }
        }

        return new SourceOutcome($evaluated, $candidates);
    }
}
