<?php

namespace App\Alerts\Detectors;

use App\Alerts\AlertCandidate;
use App\Alerts\AlertContext;
use App\Alerts\AlertType;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Prompt;

/**
 * competitor_takeover (docs/spec.md 11.1): on a core prompt, a competitor whose first-place rate was at or
 * below the threshold (percent) in the previous seven days is above it in the latest seven. Both windows
 * need enough list answers for the prompt (ADR 0017).
 */
final readonly class CompetitorTakeover implements Detector
{
    public function __construct(private MetricsService $metrics) {}

    public function detect(AlertContext $context): array
    {
        $candidates = [];
        $minimum = AlertType::CompetitorTakeover->minResponses();

        foreach ($context->brands as $brand) {
            $rule = $context->rule(AlertType::CompetitorTakeover, $brand->id);
            if ($rule === null) {
                continue;
            }
            $threshold = (float) $rule->threshold;

            $promptIds = Prompt::query()
                ->where('priority', 'core')->where('is_active', true)
                ->whereIn('market_id', $brand->markets()->where('is_active', true)->pluck('id'))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($promptIds === []) {
                continue;
            }

            [$from, $to] = $context->currentWindow();
            [$previousFrom, $previousTo] = $context->previousWindow();
            $current = $this->metrics->competitorFirstPlace(new MetricsFilter($brand->id, $from, $to, promptIds: $promptIds));
            $previous = $this->metrics->competitorFirstPlace(new MetricsFilter($brand->id, $previousFrom, $previousTo, promptIds: $promptIds));

            foreach ($current as $promptId => $competitors) {
                foreach ($competitors as $competitorId => $now) {
                    $before = $previous[$promptId][$competitorId] ?? null;
                    if ($before === null || $now->value === null || $before->value === null) {
                        continue;
                    }
                    if ($now->n < $minimum || $before->n < $minimum) {
                        continue;
                    }
                    if (round($before->value * 100, 6) > $threshold || round($now->value * 100, 6) <= $threshold) {
                        continue;
                    }

                    $candidates[] = new AlertCandidate(AlertType::CompetitorTakeover, $brand->id, "prompt:{$promptId}:competitor:{$competitorId}", [
                        'prompt_id' => $promptId,
                        'competitor_id' => $competitorId,
                        'previous' => $before->toArray(),
                        'current' => $now->toArray(),
                        'threshold_percent' => $threshold,
                        'current_window' => [$from, $to],
                        'previous_window' => [$previousFrom, $previousTo],
                    ]);
                }
            }
        }

        return $candidates;
    }
}
