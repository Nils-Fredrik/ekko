<?php

namespace App\Alerts\Detectors;

use App\Alerts\AlertCandidate;
use App\Alerts\AlertContext;
use App\Alerts\AlertType;
use App\Metrics\MetricsService;

/**
 * model_change (docs/spec.md 11.1, ADR 0017): an engine's dominant model id of a day differs from the
 * dominant model id of the previous day with answers. Providers alternate between versions during a day,
 * so the markers in model_changes (the first answer with another id) would give false changes.
 */
final readonly class ModelChange implements Detector
{
    public function __construct(private MetricsService $metrics) {}

    public function detect(AlertContext $context): array
    {
        if ($context->rule(AlertType::ModelChange, null) === null) {
            return [];
        }

        $from = $context->today()->subDays((int) config('ekko.alerts.model_history_days'))->toDateString();
        $to = $context->today()->subDay()->toDateString();
        $freshFrom = $context->today()->subDays((int) config('ekko.alerts.fresh_days'))->toDateString();

        $candidates = [];
        foreach ($this->metrics->dominantModels($from, $to) as $engine => $days) {
            $previous = null;
            foreach ($days as $day => $model) {
                if ($previous !== null && $previous !== $model && (string) $day >= $freshFrom) {
                    $candidates[] = new AlertCandidate(AlertType::ModelChange, null, "engine:{$engine}:{$day}:{$model}", [
                        'engine_key' => $engine,
                        'date' => (string) $day,
                        'from_model' => $previous,
                        'to_model' => $model,
                    ]);
                }
                $previous = $model;
            }
        }

        return $candidates;
    }
}
