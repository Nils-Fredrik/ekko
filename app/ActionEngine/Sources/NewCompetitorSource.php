<?php

namespace App\ActionEngine\Sources;

use App\ActionEngine\ActionCandidate;
use App\ActionEngine\ActionSource;
use App\ActionEngine\GenerationContext;
use App\ActionEngine\SourceOutcome;
use App\Alerts\AlertType;
use App\Enums\ActionType;
use App\Metrics\Formulas;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\AlertRule;
use App\Models\BrandAlias;
use App\Models\Competitor;
use App\Models\CompetitorAlias;

/**
 * new_competitor (docs/spec.md 11.1 and 11.3): an unknown brand in more than the threshold of the answers in a
 * market over the latest seven days, with at least as many answers as the alert needs. The threshold is the
 * brand's active new_competitor alert rule, else the alert default. The brand's own name and aliases and its
 * competitors and their aliases (added after the answers were extracted) are skipped.
 */
final readonly class NewCompetitorSource implements ActionSource
{
    public function __construct(private MetricsService $metrics) {}

    public function type(): ActionType
    {
        return ActionType::NewCompetitor;
    }

    public function evaluate(GenerationContext $context): SourceOutcome
    {
        $brand = $context->brand;
        $days = (int) config('ekko.actions.new_competitor.window_days');
        [$from, $to] = $context->window($days);
        $minimum = AlertType::NewCompetitor->minResponses();
        $rule = AlertRule::query()->where('type', AlertType::NewCompetitor->value)->where('brand_id', $brand->id)->where('is_active', true)->whereNotNull('threshold')->first();
        $threshold = $rule !== null ? (float) $rule->threshold : (float) config('ekko.actions.new_competitor.default_threshold_percent');

        // Names added after the answers were extracted stay unknown in old mentions: the brand's own name and
        // aliases are not a new competitor, nor are its competitors and their aliases.
        $known = collect([$brand->name])
            ->merge(BrandAlias::query()->where('brand_id', $brand->id)->pluck('alias'))
            ->merge(Competitor::query()->where('brand_id', $brand->id)->pluck('name'))
            ->merge(CompetitorAlias::query()->whereIn('competitor_id', Competitor::query()->where('brand_id', $brand->id)->select('id'))->pluck('alias'))
            ->map(fn ($name): string => mb_strtolower(trim((string) $name)))->all();

        $evaluated = [];
        $candidates = [];
        foreach ($context->markets as $market) {
            $filter = new MetricsFilter($brand->id, $from, $to, marketIds: [$market->id]);
            if ($this->metrics->reach($filter)['responses'] < $minimum) {
                continue;
            }
            $evaluated[] = $market->id;

            foreach ($this->metrics->unknownBrands($filter) as $unknown) {
                $metric = $unknown['metric'];
                if ($metric->value === null || $metric->n < $minimum || round($metric->value * 100, 6) <= $threshold
                    || in_array($unknown['key'], $known, true)) {
                    continue;
                }
                $detail = $this->metrics->unknownBrandDetail($filter, $unknown['key']);
                $share = (int) round($metric->value * 100);

                $candidates[] = new ActionCandidate(
                    type: ActionType::NewCompetitor,
                    marketId: $market->id,
                    subject: $unknown['key'],
                    evidence: [
                        'period' => ['from' => $from, 'to' => $to, 'days' => $days],
                        'name' => $unknown['name'],
                        'answers' => $detail['answers'],
                        'answers_without_brand' => $detail['without_brand'],
                        'responses' => $metric->n,
                        'share' => $metric->toArray(),
                        'threshold_percent' => $threshold,
                    ],
                    affectedPerMonth: GenerationContext::perMonth($detail['answers'], $days),
                    competitorOnlyShare: (float) (Formulas::ratio($detail['without_brand'], $detail['answers']) ?? 0.0),
                    competitorEvidence: [['name' => $unknown['name'], 'answers' => $detail['answers'], 'of' => $metric->n]],
                    prompts: $detail['prompts'],
                    engineKeys: $detail['engines'],
                    textParams: [
                        'name' => $unknown['name'],
                        'share' => $share,
                        // The country code; ActionTemplates shows it as the country's name in the text's language.
                        'country' => strtoupper((string) $market->country),
                        'days' => $days,
                    ],
                );
            }
        }

        return new SourceOutcome($evaluated, $candidates);
    }
}
