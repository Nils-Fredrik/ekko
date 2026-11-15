<?php

namespace App\Onboarding;

use App\Engines\Credentials\TenantApiKeys;
use App\Engines\EnginePricing;
use App\Engines\EngineResult;
use App\Extraction\ExtractionCost;
use App\Extraction\ExtractionOutcome;
use App\Models\Engine;
use App\Models\Plan;
use App\Models\Team;
use App\Usage\BillingAccount;
use App\Usage\MonthlyUsage;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Estimated monthly cost of an engine setup, shown in the wizard before the user confirms (docs/spec.md 7.4
 * and 13). It uses the engine prices valid now (`engine_prices`, via {@see EnginePricing}), what a call is
 * assumed to use (config ekko.wizard.estimate), the extraction price (ADR 0011) and the tenant's plan and
 * usage this month (ADR 0013). It writes nothing and is only an estimate: answer length and the number of
 * searches decide the real cost. Must run in the tenant's context.
 */
final class CostEstimator
{
    /** Order of frequencies, lowest first. */
    private const FREQUENCIES = ['off', 'monthly', 'weekly', 'daily'];

    public function __construct(
        private readonly EnginePricing $pricing,
        private readonly TenantApiKeys $keys,
        private readonly ExtractionCost $extraction,
        private readonly MonthlyUsage $usage,
    ) {}

    /**
     * @param  list<array{engine_key: string, frequency: string, prompt_scope: string, repetitions: int}>  $setup
     * @return array{
     *     engines: list<array{engine_key: string, calls: int, cost_usd: string, billed_to: string}>,
     *     calls: int,
     *     engine_cost_usd: string,
     *     extraction_cost_usd: string,
     *     total_cost_usd: string,
     *     platform_cost_usd: string,
     *     byok_cost_usd: string,
     *     issues: list<array{code: string, engine_key?: string}>,
     * }
     */
    public function estimate(Team $tenant, array $setup, int $promptCount, int $corePromptCount, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now('UTC');
        /** @var array{runs_per_month: array<string, string>, call_usage: array<string, array{input_tokens: int, output_tokens: int, search_calls: int}>, extraction_usage: array{input_tokens: int, output_tokens: int}} $config */
        $config = config('ekko.wizard.estimate');
        $plan = $tenant->plan()->first();

        $issues = [];
        $engines = [];
        $totalCalls = 0;
        $platform = BigDecimal::zero();
        $byok = BigDecimal::zero();

        if ($plan instanceof Plan && $plan->max_prompts_per_market !== null && $promptCount > $plan->max_prompts_per_market) {
            $issues[] = ['code' => 'prompts_above_plan'];
        }

        foreach ($setup as $row) {
            $engineKey = $row['engine_key'];
            $engine = Engine::query()->find($engineKey);
            if ($engine === null || ! $engine->is_enabled) {
                $issues[] = ['code' => 'unknown_engine', 'engine_key' => $engineKey];

                continue;
            }
            if ($plan instanceof Plan) {
                if (! $plan->allowsEngine($engineKey)) {
                    $issues[] = ['code' => 'engine_not_in_plan', 'engine_key' => $engineKey];
                }
                if ($this->rank($row['frequency']) > $this->rank($plan->maxFrequencyForEngine($engineKey))) {
                    $issues[] = ['code' => 'frequency_above_plan', 'engine_key' => $engineKey];
                }
                if ($row['repetitions'] > $plan->max_repetitions) {
                    $issues[] = ['code' => 'repetitions_above_plan', 'engine_key' => $engineKey];
                }
            }

            try {
                $price = $this->pricing->effectiveAt($engineKey, $at);
            } catch (ModelNotFoundException) {
                $issues[] = ['code' => 'no_price', 'engine_key' => $engineKey];

                continue;
            }

            $prompts = $row['prompt_scope'] === 'core' ? $corePromptCount : $promptCount;
            $calls = (int) BigDecimal::of($prompts * $row['repetitions'])
                ->multipliedBy($config['runs_per_month'][$row['frequency']] ?? '0')
                ->toScale(0, RoundingMode::Up)
                ->toInt();

            $usage = $config['call_usage'][$engine->method] ?? ['input_tokens' => 0, 'output_tokens' => 0, 'search_calls' => 0];
            $perCall = BigDecimal::of($this->pricing->cost($price, new EngineResult('', null, [], $usage['input_tokens'], $usage['output_tokens'], $usage['search_calls'], [])));
            // Rounded to cents per engine, and the totals add the rounded amounts, so what the wizard shows adds up.
            $cost = $perCall->multipliedBy($calls)->toScale(2, RoundingMode::HalfUp);

            $billing = $this->keys->forEngine($tenant, $engine) === null ? BillingAccount::Platform : BillingAccount::Byok;
            if ($billing === BillingAccount::Byok) {
                $byok = $byok->plus($cost);
            } else {
                $platform = $platform->plus($cost);
            }

            $totalCalls += $calls;
            $engines[] = [
                'engine_key' => $engineKey,
                'calls' => $calls,
                'cost_usd' => (string) $cost->toScale(2, RoundingMode::HalfUp),
                'billed_to' => $billing->value,
            ];
        }

        // Every answer is extracted once with the batch price, whoever paid for the engine call.
        $extractionPerAnswer = BigDecimal::of($this->extraction->of(new ExtractionOutcome('estimate', true, null, $config['extraction_usage']['input_tokens'], $config['extraction_usage']['output_tokens'], true)));
        $extraction = $extractionPerAnswer->multipliedBy($totalCalls)->toScale(2, RoundingMode::HalfUp);
        $platform = $platform->plus($extraction);

        $issues = [...$issues, ...$this->limitIssues($tenant, $at, $totalCalls, $platform, $byok)];

        return [
            'engines' => $engines,
            'calls' => $totalCalls,
            'engine_cost_usd' => (string) $platform->plus($byok)->minus($extraction)->toScale(2, RoundingMode::HalfUp),
            'extraction_cost_usd' => (string) $extraction->toScale(2, RoundingMode::HalfUp),
            'total_cost_usd' => (string) $platform->plus($byok)->toScale(2, RoundingMode::HalfUp),
            'platform_cost_usd' => (string) $platform->toScale(2, RoundingMode::HalfUp),
            'byok_cost_usd' => (string) $byok->toScale(2, RoundingMode::HalfUp),
            'issues' => $issues,
        ];
    }

    /**
     * The setup against what is left of this month's call quota and budgets (ADR 0013). The estimate is a
     * month's worth of calls on top of what has been used so far, so it is a warning, not a refusal: the
     * dispatcher enforces the limits when the runs are planned.
     *
     * @return list<array{code: string}>
     */
    private function limitIssues(Team $tenant, CarbonImmutable $at, int $calls, BigDecimal $platform, BigDecimal $byok): array
    {
        $usage = $this->usage->snapshot($tenant, $at);
        $issues = [];

        if ($usage->quota_calls !== null && $usage->calls + $calls > $usage->quota_calls) {
            $issues[] = ['code' => 'exceeds_call_quota'];
        }
        if ($usage->budget_usd !== null && BigDecimal::of($usage->cost_usd)->plus($platform)->isGreaterThan($usage->budget_usd)) {
            $issues[] = ['code' => 'exceeds_budget'];
        }
        if ($usage->byok_budget_usd !== null && BigDecimal::of($usage->byok_cost_usd)->plus($byok)->isGreaterThan($usage->byok_budget_usd)) {
            $issues[] = ['code' => 'exceeds_byok_budget'];
        }

        return $issues;
    }

    private function rank(string $frequency): int
    {
        $rank = array_search($frequency, self::FREQUENCIES, true);

        return $rank === false ? -1 : $rank;
    }
}
