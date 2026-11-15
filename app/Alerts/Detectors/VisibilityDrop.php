<?php

namespace App\Alerts\Detectors;

use App\Alerts\AlertCandidate;
use App\Alerts\AlertContext;
use App\Alerts\AlertType;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Market;

/**
 * visibility_drop (docs/spec.md 11.1): visibility for a market and an engine falls by more than the
 * threshold (percentage points) from the previous seven days to the latest seven, with n at least 30 in both.
 */
final readonly class VisibilityDrop implements Detector
{
    public function __construct(private MetricsService $metrics) {}

    public function detect(AlertContext $context): array
    {
        $candidates = [];
        $minimum = AlertType::VisibilityDrop->minResponses();

        foreach ($context->brands as $brand) {
            $rule = $context->rule(AlertType::VisibilityDrop, $brand->id);
            if ($rule === null) {
                continue;
            }
            $threshold = (float) $rule->threshold;

            $markets = Market::query()->where('brand_id', $brand->id)->where('is_active', true)->orderBy('id')->get();
            foreach ($markets as $market) {
                $current = $this->metrics->breakdown($this->filter($brand->id, $market->id, $context->currentWindow()), 'engine');
                $previous = $this->metrics->breakdown($this->filter($brand->id, $market->id, $context->previousWindow()), 'engine');

                foreach ($current as $engine => $now) {
                    $before = $previous[$engine] ?? null;
                    if ($before === null || $before->visibility->value === null || $now->visibility->value === null) {
                        continue;
                    }
                    if ($before->visibility->n < $minimum || $now->visibility->n < $minimum) {
                        continue;
                    }

                    $drop = round(($before->visibility->value - $now->visibility->value) * 100, 6);
                    if ($drop <= $threshold) {
                        continue;
                    }

                    $candidates[] = new AlertCandidate(AlertType::VisibilityDrop, $brand->id, "market:{$market->id}:engine:{$engine}", [
                        'market_id' => $market->id,
                        'engine_key' => (string) $engine,
                        'previous' => $before->visibility->toArray(),
                        'current' => $now->visibility->toArray(),
                        'drop_points' => round($drop, 1),
                        'threshold_points' => $threshold,
                        'current_window' => $context->currentWindow(),
                        'previous_window' => $context->previousWindow(),
                    ]);
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  array{0: string, 1: string}  $window
     */
    private function filter(int $brandId, int $marketId, array $window): MetricsFilter
    {
        return new MetricsFilter($brandId, $window[0], $window[1], marketIds: [$marketId]);
    }
}
