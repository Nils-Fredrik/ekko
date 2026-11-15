<?php

// T25 – the wizard's backend over HTTP (docs/spec.md 13): proposals as JSON, saved nowhere until confirmation,
// with usage, quota, roles and tenant isolation. DNS, HTTP and the model are fakes.

use App\Jobs\Middleware\SetTenantContext;
use App\Jobs\RunOnboardingAnalysis;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use App\Models\UsageEvent;
use App\Models\User;
use App\Onboarding\Analysis;
use App\Onboarding\AnalysisStore;
use App\Onboarding\Fetch\FetchFailed;
use App\Onboarding\Fetch\TransportResponse;
use App\Onboarding\OnboardingRunner;
use App\Scheduling\CloudRunAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Onboarding\FakePageTransport;
use Tests\Support\Onboarding\OnboardingFakes;

require_once __DIR__.'/../../Support/Onboarding/helpers.php';

it('returns the proposal as structured JSON and saves nothing before confirmation', function () {
    [$tenant, $owner] = wizardTenant();
    $fakes = OnboardingFakes::bind();
    $before = configurationRowCount();

    $response = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis());
    $id = $response->json('id');
    $response->assertStatus(202)
        ->assertJsonPath('kind', 'brand')
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('saved', false)
        ->assertJsonPath('error', null)
        ->assertJsonPath('input', ['name' => 'Eksempel AS', 'primary_domain' => 'eksempel.no', 'country' => 'NO', 'language' => 'nb'])
        ->assertJsonPath('proposal.industry', 'Retail')
        ->assertJsonPath('proposal.category', 'Footwear')
        ->assertJsonPath('proposal.name_variants', ['Eksempel', 'Eksempel Sko'])
        ->assertJsonPath('proposal.competitors.0', ['name' => 'Skoringen', 'domain' => 'skoringen.example.no'])
        ->assertJsonPath('proposal.topics.0', ['name' => 'Løpesko', 'cross_market_key' => 'running_shoes'])
        ->assertJsonPath('proposal.prompts.0.priority', 'core')
        ->assertJsonCount(12, 'proposal.prompts')
        ->assertJsonPath('pages', ['https://eksempel.no/', 'https://eksempel.no/om-oss', 'https://eksempel.no/produkter'])
        ->assertHeader('Location', url("/onboarding/analyses/{$id}"));

    expect(configurationRowCount())->toBe($before)->and($before)->toBe(0);
    // One model call, with the site text in it.
    expect($fakes->model->requests)->toHaveCount(1)
        ->and($fakes->model->requests[0]->user)->toContain('Sko til alle')->toContain('Vi selger sko');
});

it('can be polled until ready, and is a 404 once the proposal has expired', function () {
    [$tenant, $owner] = wizardTenant();
    OnboardingFakes::bind();
    Queue::fake();

    $id = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())
        ->assertStatus(202)->assertJsonPath('status', 'queued')->assertJsonPath('proposal', null)->json('id');
    Queue::assertPushed(RunOnboardingAnalysis::class, fn (RunOnboardingAnalysis $job) => $job->tenantId === $tenant->id && $job->analysisId === $id);

    $this->getJson("/onboarding/analyses/{$id}")->assertOk()->assertJsonPath('status', 'queued');

    // The queue worker runs the job.
    (new RunOnboardingAnalysis($tenant->id, $id))->handle(app(AnalysisStore::class), app(OnboardingRunner::class));
    $this->getJson("/onboarding/analyses/{$id}")->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('proposal.industry', 'Retail');

    $this->travel(25)->hours();
    $this->getJson("/onboarding/analyses/{$id}")->assertNotFound();
});

it('sets the job up for a tenant without a job-wide transaction, and never retries it', function () {
    $job = new RunOnboardingAnalysis(7, 'x');

    expect($job->tries)->toBe(1)
        ->and(array_map(fn (object $m) => $m::class, $job->middleware()))->toBe([SetTenantContext::class]);
});

it('logs the model call as onboarding usage on the platform, with the cost from the config', function () {
    [$tenant, $owner] = wizardTenant();
    OnboardingFakes::bind();

    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(202);

    $events = inTenant($tenant, fn () => UsageEvent::query()->get());
    expect($events)->toHaveCount(1)
        ->and($events[0]->kind)->toBe('onboarding')
        ->and($events[0]->engine_key)->toBeNull()
        ->and($events[0]->calls)->toBe(1)
        ->and($events[0]->input_tokens)->toBe(4000)
        ->and($events[0]->output_tokens)->toBe(3000)
        ->and($events[0]->billed_to)->toBe('platform')
        // 4000 * 2.00 / 1e6 + 3000 * 10.00 / 1e6 (Sonnet 5.5)
        ->and((string) $events[0]->cost_usd)->toBe('0.03800000');
});

it('turns the domain the user typed into a bare host, and refuses what cannot be a public site', function (string $domain, ?string $host) {
    [$tenant, $owner] = wizardTenant();
    $fakes = OnboardingFakes::bind(routes: ['https://eksempel.no/' => FakePageTransport::html(OnboardingFakes::frontPage())]);
    Queue::fake();

    $response = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis(['primary_domain' => $domain]));

    if ($host === null) {
        $response->assertStatus(422)->assertJsonValidationErrors('primary_domain');
        Queue::assertNothingPushed();
    } else {
        $response->assertStatus(202)->assertJsonPath('input.primary_domain', $host);
    }
})->with([
    ['eksempel.no', 'eksempel.no'],
    ['https://www.Eksempel.no/produkter', 'www.eksempel.no'],
    ['http://eksempel.no', 'eksempel.no'],
    ['localhost', null],
    ['127.0.0.1', null],
    ['http://169.254.169.254/latest/meta-data/', null],
    ['https://[::1]/', null],
    ['2130706433', null],
    ['https://user:pw@eksempel.no/', null],
    ['eksempel.no:8443', null],
    ['file:///etc/passwd', null],
    ['intern.local', null],
]);

it('fails the analysis when the site resolves to a private address, without calling the model or logging usage', function () {
    [$tenant, $owner] = wizardTenant();
    $fakes = OnboardingFakes::bind();
    $fakes->resolver->hosts = ['*' => ['169.254.169.254']];

    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())
        ->assertStatus(202)
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('proposal', null)
        ->assertJsonPath('error.code', 'fetch_blocked_address')
        ->assertJsonPath('error.message', __('onboarding.errors.fetch_blocked_address'));

    expect($fakes->transport->requests)->toBe([])
        ->and($fakes->model->requests)->toBe([])
        ->and(inTenant($tenant, fn () => UsageEvent::query()->count()))->toBe(0);
});

it('fails the analysis, with a code, when the front page cannot be read', function (string $reason, FetchFailed|TransportResponse $response) {
    [$tenant, $owner] = wizardTenant();
    $fakes = OnboardingFakes::bind(routes: ['https://eksempel.no/' => $response]);
    $this->withoutExceptionHandling();

    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('error.code', $reason);

    expect($fakes->model->requests)->toBe([]);
})->with([
    ['fetch_http_error', fn () => new TransportResponse(500, 'text/html', null, '')],
    ['fetch_too_large', fn () => new TransportResponse(200, 'text/html', null, 'x', truncated: true)],
    ['fetch_unsupported_content', fn () => new TransportResponse(200, 'application/pdf', null, '%PDF')],
    ['fetch_timeout', fn () => FetchFailed::because('timeout')],
]);

it('treats a model that cannot be reached as a failed analysis with no usage and no retry', function () {
    [$tenant, $owner] = wizardTenant();
    $fakes = OnboardingFakes::bind();
    $fakes->model->unavailable = true;

    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('error.code', 'model_unavailable');

    expect($fakes->model->requests)->toHaveCount(1)
        ->and(inTenant($tenant, fn () => UsageEvent::query()->count()))->toBe(0);
});

it('logs usage for a model answer that is refused, cut off or not valid, since it was billed', function (?string $failure, ?string $text, string $code) {
    [$tenant, $owner] = wizardTenant();
    $fakes = OnboardingFakes::bind();
    $fakes->model->failure = $failure;
    $fakes->model->text = $text;

    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('proposal', null)
        ->assertJsonPath('error.code', $code);

    expect(inTenant($tenant, fn () => UsageEvent::query()->where('kind', 'onboarding')->count()))->toBe(1)
        ->and(configurationRowCount())->toBe(0);
})->with([
    'refusal' => ['refusal', null, 'model_refused'],
    'cut off' => ['max_tokens', null, 'model_truncated'],
    'not json' => [null, 'Beklager, det kan jeg ikke.', 'invalid_model_output'],
    'wrong shape' => [null, '{"mentions": []}', 'invalid_model_output'],
]);

it('reports what was dropped from the model answer as warnings in the user language', function () {
    [$tenant, $owner] = wizardTenant();
    $data = json_decode(OnboardingFakes::brandJson(), true);
    $data['prompts'][] = ['text' => 'Send meg en e-post til ola@eksempel.no om sko', 'cross_market_key' => 'running_shoes', 'intent' => 'brand', 'funnel_stage' => 'decision', 'is_core' => false];
    OnboardingFakes::bind(json_encode($data));

    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())
        ->assertJsonCount(12, 'proposal.prompts')
        ->assertJsonPath('warnings', [['code' => 'prompt_personal_data', 'message' => __('onboarding.warnings.prompt_personal_data')]]);
});

it('refuses a failing analysis without leaking the exception, and marks it failed', function () {
    [$tenant, $owner] = wizardTenant();
    OnboardingFakes::bind();
    $analysis = app(AnalysisStore::class)->create($tenant->id, $owner->id, Analysis::BRAND, ['name' => 'x']);

    (new RunOnboardingAnalysis($tenant->id, $analysis->id))->failed(new RuntimeException('SECRET-DETAIL'));

    $this->actingAs($owner)->getJson("/onboarding/analyses/{$analysis->id}")
        ->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('error.code', 'internal');
    expect(json_encode(Cache::get("onboarding:analysis:{$tenant->id}:{$analysis->id}")))->not->toContain('SECRET-DETAIL');
});

it('does not run a finished analysis again', function () {
    [$tenant, $owner] = wizardTenant();
    $fakes = OnboardingFakes::bind();
    $analysis = app(AnalysisStore::class)->create($tenant->id, $owner->id, Analysis::BRAND, ['name' => 'x']);
    $analysis->status = Analysis::READY;
    app(AnalysisStore::class)->save($analysis);

    (new RunOnboardingAnalysis($tenant->id, $analysis->id))->handle(app(AnalysisStore::class), app(OnboardingRunner::class));

    expect($fakes->model->requests)->toBe([]);
});

it('checks the quota before it starts, and starts nothing when the call quota is used up', function () {
    $plan = Plan::factory()->create(['monthly_call_quota' => 0]);
    [$tenant, $owner] = wizardTenant($plan);
    $fakes = OnboardingFakes::bind();
    Queue::fake();

    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'quota_reached');

    Queue::assertNothingPushed();
    expect($fakes->model->requests)->toBe([]);
});

it('counts started analyses against the quota until they are billed', function () {
    $plan = Plan::factory()->create(['monthly_call_quota' => 2, 'max_brands' => null]);
    [$tenant, $owner] = wizardTenant($plan);
    $fakes = OnboardingFakes::bind();
    Queue::fake();

    // Two queued analyses use the two calls left, so the third is refused before any of them has run.
    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(202);
    $first = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(202)->json('id');
    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'quota_reached');
    Queue::assertPushed(RunOnboardingAnalysis::class, 2);
    expect(app(AnalysisStore::class)->pending($tenant->id))->toBe(2);

    // A finished analysis is no longer pending: its usage event counts instead.
    $store = app(AnalysisStore::class);
    app(OnboardingRunner::class)->fail($store->find($tenant->id, $first), 'model_unavailable');
    expect($store->pending($tenant->id))->toBe(1);
    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(202);

    // A reservation whose worker died runs out.
    $this->travel((int) config('ekko.wizard.pending_reservation_seconds') + 1)->seconds();
    expect($store->pending($tenant->id))->toBe(0)->and($fakes->model->requests)->toBe([]);
});

it('does not turn an analysis that was marked failed into a proposal', function () {
    [$tenant, $owner] = wizardTenant();
    $fakes = OnboardingFakes::bind();
    Queue::fake();
    $id = $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->json('id');
    $store = app(AnalysisStore::class);

    // The job's failed() marks it failed while the first worker is still in the model call.
    $fakes->model->before = function () use ($store, $tenant, $id): void {
        app(OnboardingRunner::class)->fail($store->find($tenant->id, $id), 'internal');
    };
    (new RunOnboardingAnalysis($tenant->id, $id))->handle($store, app(OnboardingRunner::class));

    expect($store->find($tenant->id, $id)->status)->toBe(Analysis::FAILED)
        ->and($store->find($tenant->id, $id)->proposal)->toBeNull();
});

it('stops when the platform budget is used up', function () {
    $plan = Plan::factory()->create(['monthly_call_quota' => null, 'monthly_budget_usd' => '0.10', 'max_brands' => null]);
    [$tenant, $owner] = wizardTenant($plan);
    OnboardingFakes::bind();
    inTenant($tenant, fn () => UsageEvent::factory()->create(['kind' => 'engine_call', 'calls' => 1, 'cost_usd' => '0.10', 'billed_to' => 'platform', 'occurred_at' => now()]));

    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(429)->assertJsonPath('error.code', 'quota_reached');
});

it('stops when the plan allows no more brands', function () {
    $plan = Plan::factory()->create(['max_brands' => 1]);
    [$tenant, $owner] = wizardTenant($plan);
    OnboardingFakes::bind();
    inTenant($tenant, fn () => Brand::factory()->create());

    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(422)->assertJsonPath('error.code', 'brand_limit');
});

it('validates what the user typed', function (array $data, string $field) {
    [$tenant, $owner] = wizardTenant();
    OnboardingFakes::bind();

    $this->actingAs($owner)->postJson('/onboarding/analyses', $data + startBrandAnalysis())->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no name' => [['name' => ''], 'name'],
    'long name' => [['name' => str_repeat('a', 121)], 'name'],
    'no domain' => [['primary_domain' => ''], 'primary_domain'],
    'country too long' => [['country' => 'NOR'], 'country'],
    'country digits' => [['country' => '47'], 'country'],
    'language junk' => [['language' => 'norsk bokmål'], 'language'],
    'language empty' => [['language' => ''], 'language'],
]);

it('is for tenant admins only, and for no one without a login', function (string $role) {
    [$tenant, $user] = wizardTenant(role: $role);
    $fakes = OnboardingFakes::bind();
    $id = app(AnalysisStore::class)->create($tenant->id, $tenant->owner->id, Analysis::BRAND, ['name' => 'x'])->id;

    $this->actingAs($user)->postJson('/onboarding/analyses', startBrandAnalysis())->assertForbidden();
    $this->actingAs($user)->getJson("/onboarding/analyses/{$id}")->assertForbidden();
    $this->actingAs($user)->postJson('/onboarding/cost-estimate', ['prompt_count' => 1, 'engines' => [['engine_key' => 'x', 'frequency' => 'daily']]])->assertForbidden();
    expect($fakes->model->requests)->toBe([]);
})->with(['editor', 'reader']);

it('refuses guests', function () {
    $this->postJson('/onboarding/analyses', startBrandAnalysis())->assertUnauthorized();
    $this->getJson('/onboarding/analyses/'.fake()->uuid())->assertUnauthorized();
    $this->postJson('/onboarding/cost-estimate', [])->assertUnauthorized();
});

it('does not show a proposal to another tenant, even a tenant admin with the id', function () {
    [$tenantA, $ownerA] = wizardTenant();
    [$tenantB, $ownerB] = wizardTenant();
    $id = app(AnalysisStore::class)->create($tenantB->id, $ownerB->id, Analysis::BRAND, ['name' => 'Hemmelig B'])->id;

    $this->actingAs($ownerA)->getJson("/onboarding/analyses/{$id}")->assertNotFound();
    $this->actingAs($ownerB)->getJson("/onboarding/analyses/{$id}")->assertOk()->assertJsonPath('input.name', 'Hemmelig B');
    $this->actingAs($ownerA)->getJson('/onboarding/analyses/not-a-uuid')->assertNotFound();
});

it('limits how many analyses a user can start per minute', function () {
    [$tenant, $owner] = wizardTenant();
    OnboardingFakes::bind();
    Queue::fake();

    foreach (range(1, 6) as $i) {
        $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(202);
    }
    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(429);
});

// A new market on an existing brand (docs/spec.md 13, cross_market_key).

/** @return array{0: Team, 1: User, 2: Brand} */
function brandWithTopics(?Plan $plan = null): array
{
    [$tenant, $owner] = wizardTenant($plan);
    $brand = inTenant($tenant, function () {
        $brand = Brand::factory()->create(['name' => 'Fjordsko', 'primary_domain' => 'fjordsko.example.no']);
        $market = Market::factory()->for($brand)->create(['country' => 'NO', 'language' => 'nb']);
        $running = Topic::factory()->for($brand)->create(['name' => 'Løpesko', 'cross_market_key' => 'running_shoes']);
        $stores = Topic::factory()->for($brand)->create(['name' => 'Skobutikker', 'cross_market_key' => 'shoe_stores']);
        Topic::factory()->for($brand)->create(['name' => 'Uten nøkkel', 'cross_market_key' => null]);
        Prompt::factory()->for($market)->create(['topic_id' => $running->id, 'text' => 'Hvilke løpesko er best for nybegynnere?', 'priority' => 'core']);
        Prompt::factory()->for($market)->create(['topic_id' => $running->id, 'text' => 'Inaktiv prompt som ikke skal brukes', 'is_active' => false]);
        Prompt::factory()->for($market)->create(['topic_id' => $stores->id, 'text' => 'Hvor finner jeg en god skobutikk i Oslo?']);

        return $brand;
    });

    return [$tenant, $owner, $brand];
}

it('proposes prompts for a new market from the brand topics, and saves nothing', function () {
    [$tenant, $owner, $brand] = brandWithTopics();
    $fakes = OnboardingFakes::bind(OnboardingFakes::marketJson(['running_shoes', 'shoe_stores']));
    $before = configurationRowCount();

    $this->actingAs($owner)->postJson("/brands/{$brand->id}/market-proposals", ['country' => 'se', 'language' => 'SV'])
        ->assertStatus(202)
        ->assertJsonPath('kind', 'market')
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('saved', false)
        ->assertJsonPath('input', ['brand_id' => $brand->id, 'country' => 'SE', 'language' => 'sv'])
        ->assertJsonPath('proposal.prompts.0.cross_market_key', 'running_shoes')
        ->assertJsonPath('proposal.prompts.0.priority', 'core')
        ->assertJsonPath('proposal.prompts.1.cross_market_key', 'shoe_stores')
        ->assertJsonMissingPath('proposal.competitors');

    expect(configurationRowCount())->toBe($before);
    $request = $fakes->model->requests[0];
    // The existing topics and an active example prompt go to the model; the inactive prompt and the topic without a key do not.
    expect($request->user)->toContain('"cross_market_key":"running_shoes"')->toContain('Hvilke løpesko er best for nybegynnere?')
        ->not->toContain('Inaktiv prompt')->not->toContain('Uten nøkkel')
        ->and($request->user)->toContain('"country":"SE"')->toContain('"language":"sv"')
        ->and($request->schema['properties']['prompts']['items']['properties']['cross_market_key']['enum'])->toBe(['running_shoes', 'shoe_stores'])
        ->and($request->system)->toContain('Do not translate them');
    expect(inTenant($tenant, fn () => UsageEvent::query()->where('kind', 'onboarding')->count()))->toBe(1);
});

it('does not send the brand prompts of another tenant to the model', function () {
    [$tenant, $owner, $brand] = brandWithTopics();
    [$tenantB] = wizardTenant();
    inTenant($tenantB, function () {
        $brandB = Brand::factory()->create();
        $marketB = Market::factory()->for($brandB)->create();
        $topicB = Topic::factory()->for($brandB)->create(['cross_market_key' => 'running_shoes', 'name' => 'Hemmelig tema B']);
        Prompt::factory()->for($marketB)->create(['topic_id' => $topicB->id, 'text' => 'Hemmelig prompt fra B']);
    });
    $fakes = OnboardingFakes::bind(OnboardingFakes::marketJson(['running_shoes']));

    $this->actingAs($owner)->postJson("/brands/{$brand->id}/market-proposals", ['country' => 'SE', 'language' => 'sv'])->assertStatus(202);

    expect($fakes->model->requests[0]->user)->not->toContain('Hemmelig');
});

it('only uses examples from other languages than the new market', function () {
    [$tenant, $owner, $brand] = brandWithTopics();
    inTenant($tenant, function () use ($brand) {
        $svMarket = Market::factory()->for($brand)->create(['country' => 'SE', 'language' => 'sv']);
        Prompt::factory()->for($svMarket)->create(['topic_id' => Topic::query()->where('cross_market_key', 'running_shoes')->value('id'), 'text' => 'Redan svensk prompt']);
    });
    $fakes = OnboardingFakes::bind(OnboardingFakes::marketJson(['running_shoes']));

    $this->actingAs($owner)->postJson("/brands/{$brand->id}/market-proposals", ['country' => 'SE', 'language' => 'sv'])->assertStatus(202);

    expect($fakes->model->requests[0]->user)->not->toContain('Redan svensk prompt')->toContain('Hvilke løpesko');
});

it('refuses a new market for a brand in another tenant', function () {
    [$tenantA, $ownerA] = wizardTenant();
    [$tenantB, , $brandB] = brandWithTopics();
    $fakes = OnboardingFakes::bind();

    $this->actingAs($ownerA)->postJson("/brands/{$brandB->id}/market-proposals", ['country' => 'SE', 'language' => 'sv'])->assertNotFound();

    expect($fakes->model->requests)->toBe([]);
});

it('needs topics that link across markets, and a plan with room for another market', function () {
    [$tenant, $owner] = wizardTenant();
    $empty = inTenant($tenant, function () {
        $brand = Brand::factory()->create();
        Topic::factory()->for($brand)->create(['cross_market_key' => null]);

        return $brand;
    });
    OnboardingFakes::bind();

    $this->actingAs($owner)->postJson("/brands/{$empty->id}/market-proposals", ['country' => 'SE', 'language' => 'sv'])
        ->assertStatus(422)->assertJsonPath('error.code', 'no_topics');

    $plan = Plan::factory()->create(['max_markets_per_brand' => 1]);
    [, $owner2, $brand2] = brandWithTopics($plan);
    $this->actingAs($owner2)->postJson("/brands/{$brand2->id}/market-proposals", ['country' => 'SE', 'language' => 'sv'])
        ->assertStatus(422)->assertJsonPath('error.code', 'market_limit');
});

it('limits market prompts to what the plan allows per market', function () {
    $plan = Plan::factory()->create(['max_prompts_per_market' => 3, 'max_markets_per_brand' => null]);
    [, $owner, $brand] = brandWithTopics($plan);
    $fakes = OnboardingFakes::bind(json_encode(['prompts' => array_map(fn ($i) => [
        'text' => "Vilken sko är bäst för löpning nummer {$i}?", 'cross_market_key' => 'running_shoes', 'intent' => 'category', 'funnel_stage' => 'awareness', 'is_core' => false,
    ], range(1, 9))]));

    $this->actingAs($owner)->postJson("/brands/{$brand->id}/market-proposals", ['country' => 'SE', 'language' => 'sv'])
        ->assertStatus(202)->assertJsonCount(3, 'proposal.prompts');

    expect($fakes->model->requests[0]->system)->toContain('3 prompts for the new market');
});

it('validates the new market', function (array $data, string $field) {
    [, $owner, $brand] = brandWithTopics();
    OnboardingFakes::bind();

    $this->actingAs($owner)->postJson("/brands/{$brand->id}/market-proposals", $data)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no country' => [['language' => 'sv'], 'country'],
    'bad country' => [['country' => 'SWE', 'language' => 'sv'], 'country'],
    'no language' => [['country' => 'SE'], 'language'],
]);

it('runs within the time the queue gives a job before it hands it to another worker', function () {
    // A second pickup would fail at once (tries = 1) and mark the analysis failed while the first still runs.
    expect((new RunOnboardingAnalysis(1, 'x'))->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

/** Cloud Run as in WorkerLauncherTest: enabled, with a fake token, and the API calls faked by the test. */
function onboardingCloudRun(): void
{
    config(['collection.cloud_run.enabled' => true, 'collection.cloud_run.project' => 'ekko-fixture', 'collection.cloud_run.region' => 'europe-west1']);
    $token = Mockery::mock(CloudRunAccessToken::class);
    $token->shouldReceive('get')->andReturn('fixture-token');
    app()->instance(CloudRunAccessToken::class, $token);
}

it('starts the worker once the queued analysis is committed', function () {
    [$tenant, $owner] = wizardTenant();
    OnboardingFakes::bind();
    onboardingCloudRun();
    config(['queue.default' => 'database']);
    $queued = null;
    Http::fake([
        'run.googleapis.com/*/executions*' => function () use (&$queued) {
            // Only after the request has committed is the job row there for the worker.
            $queued = DB::table('jobs')->where('payload', 'like', '%RunOnboardingAnalysis%')->count();

            return Http::response(['executions' => []]);
        },
        'run.googleapis.com/*:run' => Http::response(['name' => 'operation']),
    ]);

    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(202)->assertJsonPath('status', 'queued');

    expect($queued)->toBe(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'jobs/ekko-worker:run'));
});

it('answers the request even when the worker cannot be started', function () {
    [$tenant, $owner] = wizardTenant();
    OnboardingFakes::bind();
    onboardingCloudRun();
    config(['queue.default' => 'database']);
    Http::fake(['run.googleapis.com/*' => Http::response('', 500)]);

    $this->actingAs($owner)->postJson('/onboarding/analyses', startBrandAnalysis())->assertStatus(202);

    expect(DB::table('jobs')->count())->toBe(1);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), ':run'));
});
