<?php

use App\Models\Brand;
use App\Models\Engine;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\User;
use Tests\Support\Onboarding\OnboardingFakes;

require_once __DIR__.'/../../Support/Onboarding/helpers.php';

it('saves a confirmed proposal and queues one run per core prompt', function () {
    $engine = Engine::factory()->create(['is_enabled' => true]);
    $plan = Plan::factory()->create([
        'max_brands' => 2,
        'max_markets_per_brand' => 2,
        'max_prompts_per_market' => 50,
        'allowed_engines' => [$engine->key],
        'max_frequency' => 'weekly',
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
        'engines' => [['engine_key' => $engine->key, 'frequency' => 'weekly', 'repetitions' => 1]],
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
            ->and($market->marketEngines()->where('engine_key', $engine->key)->value('frequency'))->toBe('weekly')
            ->and(Run::query()->whereIn('prompt_id', Prompt::query()->where('market_id', $market->id)->pluck('id'))->count())->toBe($core)
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
