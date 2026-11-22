<?php

namespace App\Scheduling;

use App\MeasurementPlan\MeasurementPlans;
use App\Models\Prompt;
use Carbon\CarbonImmutable;

/**
 * The first day any eligible prompt is due. The estimator's date is only its example prompt.
 */
final class NextMeasurement
{
    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  iterable<Prompt>  $prompts
     */
    public function on(array $rules, iterable $prompts, CarbonImmutable $start): ?string
    {
        $engines = collect($rules)->groupBy('engine_key');
        $next = 32;
        foreach ($prompts as $prompt) {
            foreach ($engines as $rows) {
                $rule = MeasurementPlans::ruleFor($rows, $prompt->priority === 'core');
                if ($rule === null || $rule['frequency'] === 'off') {
                    continue;
                }
                for ($i = 0; $i < $next; $i++) {
                    if (PromptSchedule::due($prompt->id, $rule['frequency'], $start->addDays($i))) {
                        $next = $i;
                        break;
                    }
                }
                if ($next === 0) {
                    return $start->toDateString();
                }
            }
        }

        return $next === 32 ? null : $start->addDays($next)->toDateString();
    }
}
