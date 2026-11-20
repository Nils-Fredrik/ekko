<?php

// Helpers for the measurement plan tests (T53, docs/spec.md 7.6). Required from the test files.

use App\MeasurementPlan\MeasurementPlans;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Onboarding/helpers.php';

if (! function_exists('planCatalogue')) {
    /**
     * The four direct engines of 7.4, enabled and priced.
     *
     * @return array<string, Engine>
     */
    function planCatalogue(array $keys = ['chatgpt_api', 'gemini_api', 'claude_haiku', 'claude_sonnet']): array
    {
        $engines = [];
        foreach ($keys as $key) {
            [$family, $provider] = match (true) {
                str_starts_with($key, 'claude') => ['claude', 'anthropic'],
                str_starts_with($key, 'gemini') => ['gemini', 'google'],
                default => ['chatgpt', 'openai'],
            };
            $engines[$key] = Engine::factory()->create(['key' => $key, 'family' => $family, 'provider' => $provider, 'is_enabled' => true]);
            EnginePrice::factory()->for($engines[$key])->create(['valid_from' => now()->subYear(), 'price_per_call_usd' => '0.01000000']);
        }

        return $engines;
    }

    /** @return array{0: Team, 1: User} */
    function planTenant(array $package = [], string $role = 'owner'): array
    {
        $plan = Plan::factory()->create($package + [
            'allowed_engines' => ['chatgpt_api', 'gemini_api', 'claude_haiku', 'claude_sonnet'],
            'max_frequency' => 'daily',
            'max_repetitions' => 3,
            'max_brands' => 10,
            'max_markets_per_brand' => 10,
            'monthly_call_quota' => 100000,
        ]);
        [$tenant, $user] = wizardTenant($plan, $role);
        $tenant->forceFill(['timezone' => 'UTC'])->save();

        return [$tenant->fresh(), $user];
    }

    /** @return array{engine_key: string, prompt_scope: string, frequency: string, repetitions: int} */
    function planRule(string $engine, string $frequency, string $scope = 'all', int $repetitions = 1): array
    {
        return ['engine_key' => $engine, 'prompt_scope' => $scope, 'frequency' => $frequency, 'repetitions' => $repetitions];
    }

    /** @return list<array{engine_key: string, prompt_scope: string, frequency: string, repetitions: int}> */
    function sortedRules(array $rules): array
    {
        usort($rules, fn (array $a, array $b): int => [$a['engine_key'], $a['prompt_scope']] <=> [$b['engine_key'], $b['prompt_scope']]);

        return array_values($rules);
    }

    /** The stored effective rules of a market, read straight from market_engines. */
    function storedMarketRules(Market $market): array
    {
        return DB::table('market_engines')->where('market_id', $market->id)->orderBy('engine_key')->orderBy('prompt_scope')
            ->get(['engine_key', 'prompt_scope', 'frequency', 'repetitions'])
            ->map(fn ($row): array => ['engine_key' => $row->engine_key, 'prompt_scope' => $row->prompt_scope, 'frequency' => $row->frequency, 'repetitions' => (int) $row->repetitions])
            ->all();
    }

    function storedStandard(Brand $brand): array
    {
        return DB::table('brand_plan_engines')->where('brand_id', $brand->id)->orderBy('engine_key')->orderBy('prompt_scope')
            ->get(['engine_key', 'prompt_scope', 'frequency', 'repetitions'])
            ->map(fn ($row): array => ['engine_key' => $row->engine_key, 'prompt_scope' => $row->prompt_scope, 'frequency' => $row->frequency, 'repetitions' => (int) $row->repetitions])
            ->all();
    }

    /**
     * A brand with a standard plan, an inheriting market A and a market B with own choices; each market has one core
     * and one standard prompt.
     *
     * @return array{brand: Brand, a: Market, b: Market, prompts: array<string, Prompt>}
     */
    function planWorld(Team $tenant, array $standard, array $own): array
    {
        return inTenant($tenant, function () use ($standard, $own): array {
            $plans = app(MeasurementPlans::class);
            $brand = Brand::factory()->create(['status' => 'active']);
            $plans->initialize($brand, $standard);
            $a = Market::factory()->for($brand)->create(['country' => 'NO', 'language' => 'nb', 'is_active' => true]);
            $plans->inherit($a);
            $b = Market::factory()->for($brand)->create(['country' => 'SE', 'language' => 'sv', 'is_active' => true]);
            $plans->own($b, $own);
            $prompts = [];
            foreach (['a' => $a, 'b' => $b] as $name => $market) {
                $prompts["{$name}_core"] = Prompt::factory()->for($market)->create(['is_active' => true, 'priority' => 'core']);
                $prompts["{$name}_standard"] = Prompt::factory()->for($market)->create(['is_active' => true, 'priority' => 'standard']);
            }

            return ['brand' => $brand, 'a' => $a->fresh(), 'b' => $b->fresh(), 'prompts' => $prompts];
        });
    }
}
