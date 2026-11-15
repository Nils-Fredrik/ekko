<?php

namespace App\Alerts\Detectors;

use App\Alerts\AlertCandidate;
use App\Alerts\AlertContext;

/**
 * Finds the conditions of one alert type for the tenant in the context. Figures come from MetricsService.
 */
interface Detector
{
    /**
     * @return list<AlertCandidate>
     */
    public function detect(AlertContext $context): array;
}
