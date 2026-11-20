<?php

namespace App\MeasurementPlan;

use App\Models\Engine;
use App\Models\Plan;
use App\Models\Team;

/**
 * The plan a new brand starts with (docs/spec.md 7.4 and 7.6). The engines and prompt scopes come from
 * 7.4 with one repetition. Each frequency is the rarest of the engine's standard, the chosen brand
 * interval and the package cap. Engines that are unavailable or outside the package are off, and a
 * tenant without a package gets every engine off.
 *
 * @phpstan-import-type PlanRule from MeasurementPlans
 */
final class StandardPlan
{
    /** Pitch projects measure with these engines only (docs/spec.md 12.6). */
    public const PITCH_ENGINES = ['chatgpt_api', 'gemini_api', 'claude_haiku'];

    /** @return list<PlanRule> */
    public static function forTenant(Team $tenant, string $interval = 'daily'): array
    {
        $plan = $tenant->plan_id === null ? null : Plan::query()->find($tenant->plan_id);

        return self::rules($plan, $interval, $tenant->kind === 'pitch');
    }

    /** @return list<PlanRule> */
    public static function rules(?Plan $plan, string $interval = 'daily', bool $pitch = false): array
    {
        Frequency::rank($interval);
        /** @var list<array{engine_key: string, prompt_scope: string, frequency: string}> $standard */
        $standard = config('ekko.measurement_plan.standard');
        $engines = Engine::query()->whereIn('key', array_column($standard, 'engine_key'))->get()->keyBy('key');
        $rules = [];
        foreach ($standard as $row) {
            $key = $row['engine_key'];
            $engine = $engines->get($key);
            $usable = $engine instanceof Engine && $engine->isAvailable() && $plan instanceof Plan && $plan->allowsEngine($key)
                && (! $pitch || in_array($key, self::PITCH_ENGINES, true));
            $frequency = $usable ? Frequency::rarest($row['frequency'], $interval, $plan->maxFrequencyForEngine($key)) : 'off';
            if ($engine === null) {
                // The catalogue does not have the engine; a rule could not reference it.
                continue;
            }
            $rules[] = [
                'engine_key' => $key,
                'prompt_scope' => $row['prompt_scope'],
                'frequency' => $frequency,
                'repetitions' => 1,
            ];
        }

        return $rules;
    }
}
