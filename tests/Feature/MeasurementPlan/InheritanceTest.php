<?php

use App\MeasurementPlan\MeasurementPlans;
use App\MeasurementPlan\StandardPlan;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Team;
use App\Models\User;
use App\Onboarding\Analysis;
use App\Onboarding\AnalysisStore;
use App\Pitch\PitchProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Onboarding\OnboardingFakes;

require_once __DIR__.'/../../Support/MeasurementPlan/helpers.php';

// c20 (task:124, docs/spec.md 7.6): new markets inherit, a change of the standard reaches inheriting markets, and a
// market with own choices keeps them until it goes back to the current standard.

beforeEach(function () {
    Http::fake();
    planCatalogue();
});

it('makes the setup chosen in the wizard the standard of a new brand and lets its first market inherit it', function () {
    [$tenant, $owner] = planTenant();
    OnboardingFakes::bind();
    $id = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(202)->json('id');
    $proposal = $this->getJson("/onboarding/analyses/{$id}")->json('proposal');
    $rules = [planRule('chatgpt_api', 'weekly'), planRule('claude_haiku', 'daily', 'all', 2), planRule('claude_sonnet', 'weekly', 'core')];

    $this->post(route('onboarding.analyses.confirm', $id), [
        'name' => 'Eksempel AS', 'primary_domain' => 'eksempel.no', 'country' => 'NO', 'language' => 'nb',
        'name_variants' => $proposal['name_variants'], 'competitors' => $proposal['competitors'],
        'topics' => $proposal['topics'], 'prompts' => $proposal['prompts'], 'engines' => $rules,
    ])->assertRedirect()->assertSessionHasNoErrors();

    inTenant($tenant, function () use ($rules): void {
        $brand = Brand::query()->sole();
        $market = Market::query()->sole();
        expect(storedStandard($brand))->toBe(sortedRules($rules))
            ->and($market->plan_mode)->toBe(Market::PLAN_INHERIT)
            ->and(storedMarketRules($market))->toBe(sortedRules($rules));
    });
});

it('lets a new market from the wizard inherit the brand plan when no own choices are sent, or start own choices from it', function (string $mode) {
    [$tenant, $owner] = planTenant();
    $standard = [planRule('chatgpt_api', 'daily'), planRule('claude_sonnet', 'weekly', 'core')];
    $brand = inTenant($tenant, function () use ($standard) {
        $brand = Brand::factory()->create(['status' => 'active']);
        $brand->topics()->create(['name' => 'Sko', 'cross_market_key' => 'shoes']);
        app(MeasurementPlans::class)->initialize($brand, $standard);

        return $brand;
    });
    $store = app(AnalysisStore::class);
    $analysis = $store->create($tenant->id, $owner->id, Analysis::MARKET, ['brand_id' => $brand->id, 'country' => 'SE', 'language' => 'sv']);
    $analysis->status = Analysis::READY;
    $store->save($analysis);

    $this->actingAs($owner)->post(route('onboarding.analyses.confirm', $analysis->id), [
        'country' => 'SE', 'language' => 'sv', 'plan_mode' => $mode,
        'prompts' => [['text' => 'Vilken sko är bäst?', 'cross_market_key' => 'shoes', 'intent' => 'category', 'funnel_stage' => 'awareness', 'priority' => 'core']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    inTenant($tenant, function () use ($standard, $mode): void {
        $market = Market::query()->where('country', 'SE')->sole();
        expect($market->plan_mode)->toBe($mode)
            ->and(storedMarketRules($market))->toBe(sortedRules($standard));
    });
})->with(['inherit', 'own']);

it('carries a change of the standard to inheriting markets and leaves own choices alone', function () {
    [$tenant, $owner] = planTenant();
    $own = [planRule('claude_haiku', 'daily', 'all', 2)];
    $w = planWorld($tenant, [planRule('chatgpt_api', 'daily'), planRule('claude_sonnet', 'weekly', 'core')], $own);
    $changed = [planRule('chatgpt_api', 'weekly', 'all', 2), planRule('claude_haiku', 'monthly'), planRule('claude_sonnet', 'daily', 'core')];

    $this->actingAs($owner)->putJson(route('brands.plan.update', $w['brand']), ['standard' => ['engines' => $changed]])
        ->assertOk()
        ->assertJsonPath('standard', sortedRules($changed))
        ->assertJsonPath('markets.0.plan_mode', 'inherit')
        ->assertJsonPath('markets.0.engines', sortedRules($changed))
        ->assertJsonPath('markets.1.plan_mode', 'own')
        ->assertJsonPath('markets.1.engines', $own);

    inTenant($tenant, function () use ($w, $changed, $own): void {
        expect(storedStandard($w['brand']))->toBe(sortedRules($changed))
            ->and(storedMarketRules($w['a']))->toBe(sortedRules($changed))
            ->and(storedMarketRules($w['b']))->toBe($own);
    });
});

it('keeps the inheritance status apart from equal values', function () {
    [$tenant, $owner] = planTenant();
    $standard = [planRule('chatgpt_api', 'daily')];
    $w = planWorld($tenant, $standard, $standard);

    $this->actingAs($owner)->putJson(route('brands.plan.update', $w['brand']), ['standard' => ['engines' => [planRule('chatgpt_api', 'monthly')]]])->assertOk();

    inTenant($tenant, function () use ($w): void {
        expect(storedMarketRules($w['a']))->toBe([planRule('chatgpt_api', 'monthly')])
            ->and(storedMarketRules($w['b']))->toBe([planRule('chatgpt_api', 'daily')])
            ->and($w['b']->fresh()->plan_mode)->toBe(Market::PLAN_OWN);
    });
});

it('starts own choices from the current setup and goes back to the current standard', function () {
    [$tenant, $owner] = planTenant();
    $first = [planRule('chatgpt_api', 'daily'), planRule('claude_sonnet', 'weekly', 'core')];
    $w = planWorld($tenant, $first, []);
    $this->actingAs($owner);

    // Own choices without engines start as a copy of what the market runs now.
    $this->putJson(route('brands.plan.update', $w['brand']), ['markets' => [['id' => $w['a']->id, 'plan_mode' => 'own']]])->assertOk();
    $later = [planRule('claude_haiku', 'weekly')];
    $this->putJson(route('brands.plan.update', $w['brand']), ['standard' => ['engines' => $later]])->assertOk();
    inTenant($tenant, function () use ($w, $first): void {
        expect($w['a']->fresh()->plan_mode)->toBe(Market::PLAN_OWN)
            ->and(storedMarketRules($w['a']))->toBe(sortedRules($first));
    });

    // An own market can change its choices, also to no engines at all.
    $this->putJson(route('brands.plan.update', $w['brand']), ['markets' => [['id' => $w['a']->id, 'plan_mode' => 'own', 'engines' => []]]])->assertOk();
    inTenant($tenant, fn () => expect(storedMarketRules($w['a']))->toBe([]));

    // "Use the brand's plan" removes the own setup and takes today's standard.
    $this->putJson(route('brands.plan.update', $w['brand']), ['markets' => [['id' => $w['a']->id, 'plan_mode' => 'inherit'], ['id' => $w['b']->id, 'plan_mode' => 'inherit']]])
        ->assertOk()->assertJsonPath('markets.0.engines', $later)->assertJsonPath('markets.1.engines', $later);
    inTenant($tenant, function () use ($w, $later): void {
        expect($w['a']->fresh()->plan_mode)->toBe(Market::PLAN_INHERIT)
            ->and(storedMarketRules($w['a']))->toBe($later)
            ->and(storedMarketRules($w['b']))->toBe($later);
    });
});

it('previews a draft for every market without writing anything', function () {
    [$tenant] = planTenant();
    $w = planWorld($tenant, [planRule('chatgpt_api', 'daily')], [planRule('claude_haiku', 'weekly')]);
    $before = inTenant($tenant, fn () => [DB::table('brand_plan_engines')->get()->toArray(), DB::table('market_engines')->get()->toArray(), DB::table('markets')->get()->toArray()]);

    $preview = inTenant($tenant, function () use ($tenant, $w): array {
        $plans = app(MeasurementPlans::class);
        $draft = $plans->draft($tenant, $w['brand'], [
            'standard' => ['engines' => [planRule('chatgpt_api', 'monthly')]],
            'markets' => [['id' => $w['b']->id, 'plan_mode' => 'inherit']],
        ]);

        return $plans->preview($w['brand'], $draft);
    });

    expect($preview[$w['a']->id])->toBe(['plan_mode' => 'inherit', 'is_active' => true, 'engines' => [planRule('chatgpt_api', 'monthly')]])
        ->and($preview[$w['b']->id])->toBe(['plan_mode' => 'inherit', 'is_active' => true, 'engines' => [planRule('chatgpt_api', 'monthly')]])
        ->and(inTenant($tenant, fn () => [DB::table('brand_plan_engines')->get()->toArray(), DB::table('market_engines')->get()->toArray(), DB::table('markets')->get()->toArray()]))->toEqual($before);
});

it('refuses a new market from the wizard that would inherit a standard that can no longer be activated', function () {
    [$tenant, $owner] = planTenant();
    $brand = inTenant($tenant, function () {
        $brand = Brand::factory()->create(['status' => 'active']);
        $brand->topics()->create(['name' => 'Sko', 'cross_market_key' => 'shoes']);
        app(MeasurementPlans::class)->initialize($brand, [planRule('chatgpt_api', 'daily')]);

        return $brand;
    });
    DB::table('engine_prices')->where('engine_key', 'chatgpt_api')->delete();
    $store = app(AnalysisStore::class);
    $analysis = $store->create($tenant->id, $owner->id, Analysis::MARKET, ['brand_id' => $brand->id, 'country' => 'SE', 'language' => 'sv']);
    $analysis->status = Analysis::READY;
    $store->save($analysis);

    $this->actingAs($owner)->post(route('onboarding.analyses.confirm', $analysis->id), [
        'country' => 'SE', 'language' => 'sv', 'plan_mode' => 'inherit',
        'prompts' => [['text' => 'Vilken sko är bäst?', 'cross_market_key' => 'shoes', 'intent' => 'category', 'funnel_stage' => 'awareness', 'priority' => 'core']],
    ])->assertSessionHasErrors(['plan_mode' => __('app.plan.standard_invalid')]);

    expect(inTenant($tenant, fn () => Market::query()->where('country', 'SE')->exists()))->toBeFalse();
});

it('gives a new pitch brand the package standard and lets its market inherit it', function () {
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->withTwoFactor()->create();
    $partner = Team::factory()->tenant()->create();

    $pitch = app(PitchProvisioner::class)->create($admin, $partner, ['name' => 'Syntetisk pitch', 'domain' => 'example.test', 'country' => 'NO', 'language' => 'nb']);

    $expected = StandardPlan::forTenant($pitch);
    expect(collect($expected)->where('frequency', '!=', 'off')->pluck('engine_key')->all())->each->toBeIn(StandardPlan::PITCH_ENGINES);
    inTenant($pitch, function () use ($expected): void {
        $market = Market::query()->sole();
        expect(storedStandard(Brand::query()->sole()))->toBe(sortedRules($expected))
            ->and($market->plan_mode)->toBe(Market::PLAN_INHERIT)
            ->and(storedMarketRules($market))->toBe(sortedRules($expected));
    });
});
