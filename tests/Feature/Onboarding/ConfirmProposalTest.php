<?php

use App\Models\Brand;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\User;
use App\Onboarding\Analysis;
use App\Onboarding\AnalysisStore;
use App\Onboarding\FirstRun;
use App\Scheduling\PromptSchedule;
use Carbon\CarbonImmutable;
use Tests\Support\Onboarding\OnboardingFakes;

require_once __DIR__.'/../../Support/Onboarding/helpers.php';

it('configures every available pitch engine daily regardless of edited engine choices', function () {
    $engine = Engine::factory()->create(['key' => 'chatgpt_api', 'provider' => 'openai', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.01000000']);
    [$tenant, $owner] = wizardTenant(Plan::factory()->create(['allowed_engines' => [$engine->key], 'max_prompts_per_market' => 25]));
    $tenant->forceFill(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)])->save();
    OnboardingFakes::bind();
    $id = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(202)->json('id');
    $proposal = $this->getJson("/onboarding/analyses/{$id}")->json('proposal');
    $this->post(route('onboarding.analyses.confirm', $id), [
        'name' => 'Eksempel AS', 'primary_domain' => 'eksempel.no', 'country' => 'NO', 'language' => 'nb',
        'name_variants' => $proposal['name_variants'], 'competitors' => $proposal['competitors'],
        'topics' => $proposal['topics'], 'prompts' => $proposal['prompts'],
        'engines' => [['engine_key' => $engine->key, 'frequency' => 'weekly', 'repetitions' => 1]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    inTenant($tenant, function () use ($engine): void {
        expect(MarketEngine::query()->where('engine_key', $engine->key)->value('frequency'))->toBe('daily')
            ->and(Run::query()->count())->toBe(Prompt::query()->count());
    });
});

it('saves a confirmed proposal and queues core plus due standard prompts', function () {
    $engine = Engine::factory()->create(['is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.01000000']);
    $plan = Plan::factory()->create([
        'max_brands' => 2,
        'max_markets_per_brand' => 2,
        'max_prompts_per_market' => 50,
        'allowed_engines' => [$engine->key],
        'max_frequency' => 'daily',
        'max_repetitions' => 1,
    ]);
    [$tenant, $owner] = wizardTenant($plan);
    OnboardingFakes::bind();

    $id = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())
        ->assertStatus(202)->assertJsonPath('status', 'ready')->json('id');
    $proposal = $this->getJson("/onboarding/analyses/{$id}")->json('proposal');

    $payload = [
        'name' => 'Eksempel AS',
        'primary_domain' => 'https://eksempel.no/sko',
        'country' => 'no',
        'language' => 'NB',
        'name_variants' => $proposal['name_variants'],
        'competitors' => $proposal['competitors'],
        'topics' => $proposal['topics'],
        'prompts' => $proposal['prompts'],
        'engines' => [['engine_key' => $engine->key, 'frequency' => 'daily', 'repetitions' => 1]],
    ];

    $reader = User::factory()->create();
    $tenant->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($tenant);
    $this->actingAs($reader)->post(route('onboarding.analyses.confirm', $id), $payload)->assertForbidden();

    $this->actingAs($owner)->post(route('onboarding.analyses.confirm', $id), $payload)->assertRedirect();

    inTenant($tenant, function () use ($engine): void {
        $brand = Brand::query()->where('name', 'Eksempel AS')->firstOrFail();
        $market = $brand->markets()->firstOrFail();
        $core = Prompt::query()->where('market_id', $market->id)->where('priority', 'core')->count();

        expect($brand->primary_domain)->toBe('eksempel.no')
            ->and($brand->aliases()->where('alias', 'Eksempel AS')->exists())->toBeTrue()
            ->and($brand->domains()->where('domain', 'eksempel.no')->exists())->toBeTrue()
            ->and($brand->competitors()->count())->toBeGreaterThan(0)
            ->and($brand->topics()->count())->toBeGreaterThan(0)
            ->and($market->country)->toBe('NO')
            ->and($market->language)->toBe('nb')
            ->and($market->marketEngines()->where('engine_key', $engine->key)->value('frequency'))->toBe('daily')
            ->and(Run::query()->whereIn('prompt_id', Prompt::query()->where('market_id', $market->id)->pluck('id'))->count())->toBe(Prompt::query()->where('market_id', $market->id)->count())
            ->and($core)->toBeGreaterThan(0);
    });

    $this->actingAs($owner)->post(route('onboarding.analyses.confirm', $id), $payload)
        ->assertSessionHasErrors('analysis');
});

it('refuses a prompt that contains an email address', function () {
    [$tenant, $owner] = wizardTenant();
    OnboardingFakes::bind();
    $id = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->json('id');
    $proposal = $this->getJson("/onboarding/analyses/{$id}")->json('proposal');
    $proposal['prompts'][0]['text'] = 'Skriv til meg på post@example.test';

    $this->actingAs($owner)->from(route('onboarding.wizard'))->post(route('onboarding.analyses.confirm', $id), [
        'name' => 'Eksempel AS',
        'primary_domain' => 'eksempel.no',
        'country' => 'NO',
        'language' => 'nb',
        'topics' => $proposal['topics'],
        'prompts' => $proposal['prompts'],
    ])->assertRedirect()->assertSessionHasErrors('prompts.0.text');

    expect(inTenant($tenant, fn () => Brand::query()->count()))->toBe(0);
});

it('accepts a prompt key that slugifies to the topic key', function () {
    $engine = Engine::factory()->create(['is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.01000000']);
    [$tenant, $owner] = wizardTenant(Plan::factory()->create([
        'allowed_engines' => [$engine->key],
        'max_frequency' => 'daily',
        'max_repetitions' => 1,
    ]));
    OnboardingFakes::bind();
    $id = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->json('id');

    $this->actingAs($owner)->post(route('onboarding.analyses.confirm', $id), [
        'name' => 'Eksempel AS',
        'primary_domain' => 'eksempel.no',
        'country' => 'NO',
        'language' => 'nb',
        'topics' => [['name' => 'Løpesko', 'cross_market_key' => 'Running Shoes']],
        'prompts' => [[
            'text' => 'Hvilken løpesko er best?',
            'cross_market_key' => 'running shoes',
            'intent' => 'category',
            'funnel_stage' => 'awareness',
            'priority' => 'core',
        ]],
        'engines' => [['engine_key' => $engine->key, 'frequency' => 'daily', 'repetitions' => 1]],
    ])->assertRedirect();

    expect(inTenant($tenant, fn () => Prompt::query()->count()))->toBe(1);
});

it('confirms a market proposal on the existing brand', function () {
    $engine = Engine::factory()->create(['is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.01000000']);
    $plan = Plan::factory()->create(['allowed_engines' => [$engine->key], 'max_frequency' => 'daily', 'max_repetitions' => 1]);
    [$tenant, $owner] = wizardTenant($plan);
    $brand = inTenant($tenant, function () {
        $brand = Brand::factory()->create(['status' => 'active']);
        $brand->topics()->create(['name' => 'Sko', 'cross_market_key' => 'shoes']);

        return $brand;
    });
    $store = app(AnalysisStore::class);
    $analysis = $store->create($tenant->id, $owner->id, Analysis::MARKET, ['brand_id' => $brand->id, 'country' => 'SE', 'language' => 'sv']);
    $analysis->status = Analysis::READY;
    $store->save($analysis);

    $this->actingAs($owner)->post(route('onboarding.analyses.confirm', $analysis->id), [
        'country' => 'SE',
        'language' => 'sv',
        'prompts' => [[
            'text' => 'Vilken sko är bäst?',
            'cross_market_key' => 'Shoes',
            'intent' => 'category',
            'funnel_stage' => 'awareness',
            'priority' => 'core',
        ]],
        'engines' => [['engine_key' => $engine->key, 'frequency' => 'daily', 'repetitions' => 1]],
    ])->assertRedirect();

    inTenant($tenant, function () use ($brand): void {
        expect($brand->markets()->where('country', 'SE')->exists())->toBeTrue()
            ->and(Prompt::query()->where('text', 'Vilken sko är bäst?')->exists())->toBeTrue();
    });
});

it('keeps only the core runs that fit the quota', function () {
    $engine = Engine::factory()->create(['key' => 'quota_engine', 'provider' => 'openai', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.01000000']);
    $plan = Plan::factory()->create([
        'allowed_engines' => [$engine->key],
        'max_frequency' => 'daily',
        'max_repetitions' => 1,
        'monthly_call_quota' => 1,
    ]);
    [$tenant] = wizardTenant($plan);
    $market = inTenant($tenant, function () use ($engine) {
        $market = Market::factory()->create(['is_active' => true]);
        $market->brand->update(['status' => 'active']);
        MarketEngine::factory()->for($market)->for($engine)->create(['frequency' => 'daily', 'prompt_scope' => 'all', 'repetitions' => 1]);
        Prompt::factory()->for($market)->count(3)->create(['is_active' => true, 'priority' => 'core']);

        return $market;
    });

    expect(inTenant($tenant, fn () => app(FirstRun::class)->start($tenant, $market)))->toBe(1);
    inTenant($tenant, function (): void {
        expect(Run::query()->where('status', 'queued')->count())->toBe(1)
            ->and(Run::query()->where('status', 'skipped_quota')->count())->toBe(2);
    });
});

it('does not queue a platform claude sonnet run on a day its weekly cadence is not due', function () {
    config(['ekko.anthropic.monthly_pool_usd' => '25']);
    $engine = Engine::factory()->create(['key' => 'claude_sonnet', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.02000000']);
    $plan = Plan::factory()->create([
        'allowed_engines' => [$engine->key],
        'max_frequency' => 'daily',
        'max_repetitions' => 1,
        'monthly_call_quota' => 100,
    ]);
    [$tenant] = wizardTenant($plan);
    $market = inTenant($tenant, function () use ($engine) {
        $market = Market::factory()->create(['is_active' => true]);
        $market->brand->update(['status' => 'active']);
        MarketEngine::factory()->for($market)->for($engine)->create(['frequency' => 'daily', 'prompt_scope' => 'all', 'repetitions' => 1]);
        Prompt::factory()->for($market)->create(['is_active' => true, 'priority' => 'core']);

        return $market;
    });
    $promptId = inTenant($tenant, fn () => (int) $market->prompts()->value('id'));
    $local = CarbonImmutable::now($tenant->timezone)->startOfDay();
    for ($i = 0; $i < 7 && PromptSchedule::due($promptId, 'weekly', $local); $i++) {
        $local = $local->addDay();
    }
    expect(PromptSchedule::due($promptId, 'weekly', $local))->toBeFalse();
    $this->travelTo($local->addHours(12));

    expect(inTenant($tenant, fn () => app(FirstRun::class)->start($tenant, $market)))->toBe(0);
    expect(inTenant($tenant, fn () => Run::query()->count()))->toBe(0);
});
