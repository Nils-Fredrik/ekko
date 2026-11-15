<?php

namespace App\Alerts\Detectors;

use App\Alerts\AlertCandidate;
use App\Alerts\AlertContext;
use App\Alerts\AlertType;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Market;

/**
 * new_competitor (docs/spec.md 11.1): a brand the detection does not know shows up in more than the
 * threshold (percent) of the answers in a market over the latest seven days. Needs at least 30 answers.
 */
final readonly class NewCompetitor implements Detector
{
    public function __construct(private MetricsService $metrics) {}

    public function detect(AlertContext $context): array
    {
        $candidates = [];
        [$from, $to] = $context->currentWindow();
        $minimum = AlertType::NewCompetitor->minResponses();

        foreach ($context->brands as $brand) {
            $rule = $context->rule(AlertType::NewCompetitor, $brand->id);
            if ($rule === null) {
                continue;
            }
            $threshold = (float) $rule->threshold;

            $markets = Market::query()->where('brand_id', $brand->id)->where('is_active', true)->orderBy('id')->get();
            foreach ($markets as $market) {
                foreach ($this->metrics->unknownBrands(new MetricsFilter($brand->id, $from, $to, marketIds: [$market->id])) as $unknown) {
                    $metric = $unknown['metric'];
                    if ($metric->value === null || $metric->n < $minimum || round($metric->value * 100, 6) <= $threshold) {
                        continue;
                    }

                    $candidates[] = new AlertCandidate(AlertType::NewCompetitor, $brand->id, "market:{$market->id}:name:".md5($unknown['key']), [
                        'market_id' => $market->id,
                        'name' => $unknown['name'],
                        'share' => $metric->toArray(),
                        'threshold_percent' => $threshold,
                        'window' => [$from, $to],
                    ]);
                }
            }
        }

        return $candidates;
    }
}
