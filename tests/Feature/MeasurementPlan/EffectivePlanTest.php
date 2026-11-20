<?php

use App\AnthropicBudget\Estimator;
use App\AnthropicBudget\Planner;
use App\Dashboard\AnalysisFilters;
use App\Models\AnthropicBudgetPlan;
use App\Models\Run;
use App\Models\User;
use App\Onboarding\FirstRun;
use App\Scheduling\Dispatcher;
use App\Scheduling\PromptSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Mcp\McpTestClient;

require_once __DIR__.'/../../Support/MeasurementPlan/helpers.php';

// docs/spec.md 7.6 and chapter 20 (T53): with one inheriting market and one with own choices, the first run, the
// dispatcher, the Anthropic forecast and the extra Sonnet runs follow each market's rules, and settings, engine filters
// and list_brands show them.

beforeEach(function () {
    Http::fake();
    planCatalogue();
    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
});

/** @return list<string> "<market>_<prompt>:<engine>:<repetition>" for the tenant's runs, sorted */
function planRuns(array $w, $tenant): array
{
    $names = array_flip(array_map(fn ($prompt) => $prompt->id, $w['prompts']));
    $runs = inTenant($tenant, fn () => Run::query()->get(['prompt_id', 'engine_key', 'repetition'])
        ->map(fn (Run $run): string => $names[$run->prompt_id].':'.$run->engine_key.':'.$run->repetition)->all());
    sort($runs);

    return $runs;
}

it('plans the first run and the daily dispatch from each market\'s own rules', function () {
    [$tenant, $owner] = planTenant();
    $w = planWorld($tenant,
        [planRule('chatgpt_api', 'daily'), planRule('claude_sonnet', 'daily', 'core'), planRule('gemini_api', 'off')],
        [planRule('chatgpt_api', 'off'), planRule('claude_haiku', 'daily', 'all', 2)],
    );

    inTenant($tenant, function () use ($tenant, $w): void {
        app(FirstRun::class)->start($tenant, $w['a']);
        app(FirstRun::class)->start($tenant, $w['b']);
    });
    expect(planRuns($w, $tenant))->toBe([
        'a_core:chatgpt_api:1', 'a_core:claude_sonnet:1', 'a_standard:chatgpt_api:1',
        'b_core:claude_haiku:1', 'b_core:claude_haiku:2', 'b_standard:claude_haiku:1', 'b_standard:claude_haiku:2',
    ]);

    // The standard changes: the inheriting market follows from the next dispatch, the own market does not.
    $this->actingAs($owner)->putJson(route('brands.plan.update', $w['brand']), ['standard' => ['engines' => [planRule('claude_haiku', 'daily')]]])->assertOk();
    inTenant($tenant, fn () => Run::query()->delete());
    $this->travel(1)->days();
    app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC'));

    expect(planRuns($w, $tenant))->toBe([
        'a_core:claude_haiku:1', 'a_standard:claude_haiku:1',
        'b_core:claude_haiku:1', 'b_core:claude_haiku:2', 'b_standard:claude_haiku:1', 'b_standard:claude_haiku:2',
    ]);
});

it('gives the extra daily Sonnet runs only to the market whose rules have daily Sonnet for core prompts', function () {
    config(['ekko.anthropic.monthly_pool_usd' => '100', 'ekko.anthropic.cycle_start_day' => 15]);
    [$tenant, $owner] = planTenant();
    $w = planWorld($tenant, [planRule('claude_sonnet', 'daily', 'core')], [planRule('claude_sonnet', 'off', 'core')]);
    // A day when the inheriting market's core prompt is not in its weekly base load.
    while (PromptSchedule::due($w['prompts']['a_core']->id, 'weekly', now('UTC'))) {
        $this->travel(1)->days();
    }

    app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC'));

    $additions = inTenant($tenant, fn () => AnthropicBudgetPlan::query()->sole()->additions);
    $sonnet = array_filter($additions, fn (array $item): bool => $item['kind'] === 'sonnet_daily');
    expect(array_values(array_unique(array_map(fn (array $item): string => $item['kind'].':'.$item['prompt'], $sonnet))))
        ->toBe(['sonnet_daily:'.$w['prompts']['a_core']->id])
        ->and(planRuns($w, $tenant))->toBe(['a_core:claude_sonnet:1']);

    // Once the market has own choices without daily Sonnet, it gets no extra run.
    $this->actingAs($owner)->putJson(route('brands.plan.update', $w['brand']), ['markets' => [['id' => $w['a']->id, 'plan_mode' => 'own', 'engines' => [planRule('claude_sonnet', 'weekly', 'core')]]]])->assertOk();
    $this->travel(1)->days();
    while (PromptSchedule::due($w['prompts']['a_core']->id, 'weekly', now('UTC'))) {
        $this->travel(1)->days();
    }
    inTenant($tenant, fn () => Run::query()->delete());
    app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC'));
    $today = inTenant($tenant, fn () => AnthropicBudgetPlan::query()->where('day', now('UTC')->toDateString())->sole()->additions);
    expect(array_filter($today, fn (array $item): bool => $item['kind'] === 'sonnet_daily'))->toBe([])
        ->and(planRuns($w, $tenant))->toBe([]);
});

it('builds the Anthropic base-load forecast from each market\'s effective rules', function () {
    config(['ekko.anthropic.monthly_pool_usd' => '100', 'ekko.anthropic.cycle_start_day' => 15]);
    [$tenant, $owner] = planTenant();
    $w = planWorld($tenant, [planRule('claude_haiku', 'daily')], [planRule('claude_haiku', 'daily', 'all', 2)]);
    $now = CarbonImmutable::now('UTC');
    $estimator = app(Estimator::class);
    $unit = inTenant($tenant, fn () => $estimator->engine('claude_haiku', $now) + $estimator->expectedExtraction($now));
    $catalog = new ReflectionMethod(Planner::class, 'catalog');
    $runsInForecast = function () use ($catalog, $tenant, $now, $unit): int {
        $result = inTenant($tenant, fn () => $catalog->invoke(app(Planner::class), $tenant->fresh(), $now, 1));

        return intdiv($result['baseline'] - $result['platform_paid'], $unit);
    };

    // Inheriting A: 2 prompts x 1; own B: 2 prompts x 2.
    expect($runsInForecast())->toBe(6);
    $this->actingAs($owner)->putJson(route('brands.plan.update', $w['brand']), ['standard' => ['engines' => [planRule('claude_haiku', 'daily', 'all', 3)]]])->assertOk();
    expect($runsInForecast())->toBe(10);
    $this->putJson(route('brands.plan.update', $w['brand']), ['markets' => [['id' => $w['b']->id, 'plan_mode' => 'inherit']]])->assertOk();
    expect($runsInForecast())->toBe(12);
});

it('shows each market\'s rules in settings, the engine filters and list_brands', function () {
    [$tenant, $owner] = planTenant();
    $standard = [planRule('chatgpt_api', 'weekly', 'all', 2), planRule('claude_sonnet', 'weekly', 'core')];
    $own = [planRule('claude_haiku', 'monthly', 'all', 3)];
    $w = planWorld($tenant, $standard, $own);

    $this->actingAs($owner)->get(route('brands.settings', $w['brand']))->assertInertia(fn (Assert $page) => $page
        ->where('measurementPlan.standard', sortedRules($standard))
        ->where('measurementPlan.markets.0.id', $w['a']->id)
        ->where('measurementPlan.markets.0.plan_mode', 'inherit')
        ->where('measurementPlan.markets.0.engines', sortedRules($standard))
        ->where('measurementPlan.markets.1.plan_mode', 'own')
        ->where('measurementPlan.markets.1.engines', $own)
        ->where('markets.0.plan_mode', 'inherit'));

    $engines = inTenant($tenant, fn () => array_column(AnalysisFilters::options($w['brand'], 'brands.show')['engine'], 'value'));
    expect($engines)->toBe(['chatgpt_api', 'claude_haiku', 'claude_sonnet']);

    $admin = User::factory()->withTwoFactor()->create();
    $tenant->users()->attach($admin, ['role' => 'admin']);
    $brands = McpTestClient::call($this, McpTestClient::connect($admin, $tenant), 'list_brands');
    $brand = collect($brands['data']['brands'])->firstWhere('id', $w['brand']->id);
    $mcp = fn (array $rules): array => array_map(fn (array $rule): array => ['engine' => $rule['engine_key'], 'frequency' => $rule['frequency'], 'prompt_scope' => $rule['prompt_scope'], 'repetitions' => $rule['repetitions']], sortedRules($rules));
    expect($brand['plan'])->toBe($mcp($standard))
        ->and($brand['markets'][0]['plan_mode'])->toBe('inherit')
        ->and($brand['markets'][0]['engines'])->toBe($mcp($standard))
        ->and($brand['markets'][1]['plan_mode'])->toBe('own')
        ->and($brand['markets'][1]['engines'])->toBe($mcp($own));
});
