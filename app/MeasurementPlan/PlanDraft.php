<?php

namespace App\MeasurementPlan;

/**
 * A validated, unsaved change to a brand's measurement plan (MeasurementPlans::draft). The estimate (T54) previews
 * it with MeasurementPlans::preview; saving applies it with MeasurementPlans::apply.
 *
 * @phpstan-import-type PlanRule from MeasurementPlans
 */
final class PlanDraft
{
    /**
     * @param  list<PlanRule>|null  $standard  the new standard, or null to keep it
     * @param  array<int, array{plan_mode: string, engines: list<PlanRule>|null}>  $markets  by market ID; engines null keeps the current rules
     */
    public function __construct(
        public readonly ?array $standard,
        public readonly array $markets,
    ) {}
}
