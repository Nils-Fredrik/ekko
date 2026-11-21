<?php

namespace App\MeasurementPlan;

use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Plan;
use App\Models\Team;
use Illuminate\Validation\ValidationException;

/**
 * The plan a new brand starts with (docs/spec.md 7.4 and 7.6). The engines and prompt scopes come from
 * 7.4 with one repetition. Each frequency is the rarest of the engine's standard, the chosen brand
 * interval and the package cap. Engines that are unavailable, unpriced or outside the package are off, and a
 * tenant without a package gets every engine off.
 *
 * @phpstan-import-type PlanRule from MeasurementPlans
 */
final class StandardPlan
{
    /** Pitch projects measure with these engines only (docs/spec.md 12.6). */
    public const PITCH_ENGINES = ['chatgpt_api', 'gemini_api', 'claude_haiku'];

    /** The fixed first-market setup that a pitch wizard will actually save.
     *
     * @return list<PlanRule>
     */
    public static function pitchWizardRules(?Plan $plan): array
    {
        $engines = Engine::query()->whereIn('key', self::PITCH_ENGINES)->orderBy('key')->get()
            ->filter(fn (Engine $engine): bool => $engine->isAvailable())->values();
        if ($plan === null || $engines->isEmpty() || ($plan->max_prompts_per_market !== null && $plan->max_prompts_per_market < 25)
            || $engines->contains(fn (Engine $engine): bool => ! $plan->allowsEngine($engine->key) || $plan->maxFrequencyForEngine($engine->key) !== 'daily')) {
            throw ValidationException::withMessages(['engines' => __('pitch.plan_unavailable')]);
        }

        return $engines->map(fn (Engine $engine): array => ['engine_key' => $engine->key, 'prompt_scope' => 'all', 'frequency' => 'daily', 'repetitions' => 1])->values()->all();
    }

    /**
     * @param  list<array{engine_key: string, prompt_scope: string, frequency: string}>|null  $standard  engines and scopes
     *                                                                                                   to start from; the configured 7.4 standard when null (a migration passes a frozen copy)
     * @return list<PlanRule>
     */
    public static function forTenant(Team $tenant, string $interval = 'daily', ?array $standard = null): array
    {
        $plan = $tenant->plan_id === null ? null : Plan::query()->find($tenant->plan_id);

        return self::rules($plan, $interval, $tenant->kind === 'pitch', $standard);
    }

    /**
     * @param  list<array{engine_key: string, prompt_scope: string, frequency: string}>|null  $standard
     * @return list<PlanRule>
     */
    public static function rules(?Plan $plan, string $interval = 'daily', bool $pitch = false, ?array $standard = null): array
    {
        Frequency::rank($interval);
        if ($standard === null) {
            /** @var list<array{engine_key: string, prompt_scope: string, frequency: string}> $configured */
            $configured = config('ekko.measurement_plan.standard');
            $standard = $configured;
        }
        $engines = Engine::query()->whereIn('key', array_column($standard, 'engine_key'))->get()->keyBy('key');
        // An engine without a current price cannot be saved as active (7.6), so it starts off.
        $priced = EnginePrice::query()->whereIn('engine_key', $engines->keys())->where('valid_from', '<=', now())->distinct()->pluck('engine_key')->all();
        $rules = [];
        foreach ($standard as $row) {
            $key = $row['engine_key'];
            $engine = $engines->get($key);
            $usable = $engine instanceof Engine && $engine->isAvailable() && $plan instanceof Plan && $plan->allowsEngine($key) && in_array($key, $priced, true)
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
