<?php

use App\Dashboard\DashboardLabels;
use App\MeasurementPlan\MeasurementPlans;
use App\MeasurementPlan\StandardPlan;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Plan;
use App\Models\Run;
use App\Onboarding\FirstRun;
use App\Scheduling\Dispatcher;
use App\Scheduling\PromptSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Onboarding\OnboardingFakes;

require_once __DIR__.'/../../Support/MeasurementPlan/helpers.php';

// c21 (task:124, docs/spec.md 7.6): engine choice, scope, core frequency and repetitions are stored together, a core
// prompt gets exactly one effective rule per engine, and the wizard and settings give the same setup.

beforeEach(function () {
    Http::fake();
    planCatalogue();
});

it('stores engine choice, scope, core frequency and repetitions together and returns them unchanged', function () {
    [$tenant, $owner] = planTenant();
    $w = planWorld($tenant, [], []);
    $rules = [
        planRule('chatgpt_api', 'weekly', 'all', 2),
        planRule('chatgpt_api', 'daily', 'core', 1),
        planRule('gemini_api', 'off'),
        planRule('claude_sonnet', 'weekly', 'core', 3),
    ];

    $this->actingAs($owner)->putJson(route('brands.plan.update', $w['brand']), ['standard' => ['engines' => $rules]])->assertOk();

    $this->getJson(route('brands.plan.show', $w['brand']))->assertOk()
        ->assertJsonPath('standard', sortedRules($rules))
        ->assertJsonPath('markets.0.engines', sortedRules($rules));
    inTenant($tenant, fn () => expect(storedStandard($w['brand']))->toBe(sortedRules($rules)));
});

it('resolves exactly one rule per engine for every prompt', function () {
    $all = planRule('chatgpt_api', 'weekly', 'all', 3);
    $core = planRule('chatgpt_api', 'daily', 'core', 1);

    expect(MeasurementPlans::ruleFor([$all, $core], true))->toBe($core)
        ->and(MeasurementPlans::ruleFor([$all, $core], false))->toBe($all)
        ->and(MeasurementPlans::ruleFor([$all], true))->toBe($all)
        ->and(MeasurementPlans::ruleFor([$core], false))->toBeNull();
});

it('runs a core prompt by its core rule alone in the first run and the dispatcher', function () {
    [$tenant] = planTenant();
    // The all rule is monthly with three repetitions, the core rule daily with one.
    $w = planWorld($tenant, [planRule('chatgpt_api', 'monthly', 'all', 3), planRule('chatgpt_api', 'daily', 'core', 1)], []);
    $core = $w['prompts']['a_core'];
    $standard = $w['prompts']['a_standard'];
    while (PromptSchedule::due($core->id, 'monthly', now('UTC')) || PromptSchedule::due($standard->id, 'monthly', now('UTC'))) {
        $this->travel(1)->days();
    }
    $runs = fn (): array => inTenant($tenant, fn () => Run::query()->orderBy('id')->get(['prompt_id', 'engine_key', 'repetition'])
        ->map(fn (Run $run): array => [$run->prompt_id, $run->engine_key, $run->repetition])->all());

    inTenant($tenant, fn () => app(FirstRun::class)->start($tenant, $w['a']));
    expect($runs())->toBe([[$core->id, 'chatgpt_api', 1]]);

    $this->travel(1)->days();
    while (PromptSchedule::due($standard->id, 'monthly', now('UTC'))) {
        $this->travel(1)->days();
    }
    app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC'));
    expect($runs())->toBe([[$core->id, 'chatgpt_api', 1], [$core->id, 'chatgpt_api', 1]]);
});

it('rejects two rules with the same engine and scope, and the database refuses them too', function () {
    [$tenant, $owner] = planTenant();
    $w = planWorld($tenant, [planRule('chatgpt_api', 'daily')], []);

    $this->actingAs($owner)->putJson(route('brands.plan.update', $w['brand']), ['standard' => ['engines' => [
        planRule('chatgpt_api', 'daily'), planRule('chatgpt_api', 'weekly'),
    ]]])->assertUnprocessable()->assertJsonValidationErrors('standard.engines.1.prompt_scope');

    inTenant($tenant, function () use ($w): void {
        expect(storedStandard($w['brand']))->toBe([planRule('chatgpt_api', 'daily')]);
        expect(fn () => DB::transaction(fn () => MarketEngine::query()->create(['market_id' => $w['a']->id] + planRule('chatgpt_api', 'weekly'))))
            ->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => $w['brand']->planEngines()->create(planRule('chatgpt_api', 'weekly'))))
            ->toThrow(QueryException::class);
    });
});

it('accepts off for any engine but holds active rules to the package, availability and prices', function () {
    [$tenant, $owner] = planTenant(['allowed_engines' => ['chatgpt_api', 'claude_haiku', 'gemini_api'], 'engine_max_frequencies' => ['claude_haiku' => 'weekly'], 'max_repetitions' => 2]);
    Engine::query()->whereKey('gemini_api')->update(['is_enabled' => false]);
    $unpriced = Engine::factory()->create(['key' => 'unpriced_api', 'is_enabled' => true]);
    $w = planWorld($tenant, [], []);
    $this->actingAs($owner);

    $this->putJson(route('brands.plan.update', $w['brand']), ['standard' => ['engines' => [
        planRule('claude_sonnet', 'weekly', 'core'),
        planRule('claude_haiku', 'daily'),
        planRule('chatgpt_api', 'daily', 'all', 3),
        planRule('gemini_api', 'daily'),
        planRule('nonexistent', 'daily'),
        planRule('chatgpt_api', 'yearly', 'core'),
    ]]])->assertUnprocessable()->assertJsonValidationErrors([
        'standard.engines.0.engine_key', 'standard.engines.1.frequency', 'standard.engines.2.repetitions',
        'standard.engines.3.engine_key', 'standard.engines.4.engine_key', 'standard.engines.5.frequency',
    ]);

    $plan = $tenant->plan;
    $plan->forceFill(['allowed_engines' => [...$plan->allowed_engines, $unpriced->key]])->save();
    $this->putJson(route('brands.plan.update', $w['brand']), ['standard' => ['engines' => [planRule($unpriced->key, 'daily')]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['standard.engines.0.engine_key' => __('onboarding.issues.no_price', ['engine' => DashboardLabels::engine($unpriced->key, 'chatgpt')])]);

    $off = [planRule('claude_sonnet', 'off', 'core'), planRule('gemini_api', 'off'), planRule($unpriced->key, 'off'), planRule('claude_haiku', 'weekly', 'all', 2)];
    $this->putJson(route('brands.plan.update', $w['brand']), ['standard' => ['engines' => $off]])->assertOk();
    inTenant($tenant, fn () => expect(storedStandard($w['brand']))->toBe(sortedRules($off)));
});

it('gives the same setup from the wizard and from brand settings', function () {
    [$tenant, $owner] = planTenant();
    $rules = [planRule('chatgpt_api', 'weekly', 'all', 2), planRule('chatgpt_api', 'daily', 'core'), planRule('claude_sonnet', 'weekly', 'core')];
    OnboardingFakes::bind();
    $id = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(202)->json('id');
    $proposal = $this->getJson("/onboarding/analyses/{$id}")->json('proposal');
    $this->post(route('onboarding.analyses.confirm', $id), [
        'name' => 'Eksempel AS', 'primary_domain' => 'eksempel.no', 'country' => 'NO', 'language' => 'nb',
        'name_variants' => $proposal['name_variants'], 'competitors' => $proposal['competitors'],
        'topics' => $proposal['topics'], 'prompts' => $proposal['prompts'], 'engines' => $rules,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $other = planWorld($tenant, [planRule('gemini_api', 'monthly')], []);
    $this->putJson(route('brands.plan.update', $other['brand']), ['standard' => ['engines' => $rules]])->assertOk();

    inTenant($tenant, function () use ($other): void {
        $wizard = Brand::query()->where('name', 'Eksempel AS')->sole();
        $wizardMarket = Market::query()->where('brand_id', $wizard->id)->sole();
        expect(storedStandard($wizard))->toBe(storedStandard($other['brand']))
            ->and(storedMarketRules($wizardMarket))->toBe(storedMarketRules($other['a']));
    });

    // The wizard and the settings page get the same plan data to edit.
    $wizardProps = $this->get(route('brands.markets.create', $other['brand']))->viewData('page')['props'];
    $settingsProps = $this->get(route('brands.setup.markets', $other['brand']))->viewData('page')['props'];
    expect($wizardProps)->not->toHaveKey('engines')
        ->and($settingsProps)->not->toHaveKey('engines')
        ->and($wizardProps['measurementPlan']['standard'])->toBe($settingsProps['measurementPlan']['standard'])
        ->and($wizardProps['measurementPlan']['options'])->toBe($settingsProps['measurementPlan']['options'])
        ->and($wizardProps['measurementPlan']['locked'])->toBeFalse();
});

it('shows a new pitch brand the fixed daily plan and does not let the wizard edit it', function () {
    [$tenant, $owner] = planTenant();
    $pitchPlan = Plan::query()->where('is_pitch_plan', true)->sole();
    $tenant->forceFill(['kind' => 'pitch', 'plan_id' => $pitchPlan->id, 'pitch_expires_at' => now()->addDays(7)])->save();

    $this->actingAs($owner)->get(route('onboarding.wizard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('measurementPlan.locked', true)
            ->where('measurementPlan.standard', StandardPlan::pitchWizardRules($pitchPlan)));
});
