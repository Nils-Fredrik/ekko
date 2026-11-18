<?php

use App\Models\Brand;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Integration;
use App\Models\MarketEngine;
use App\Models\Plan;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\TenantApiKey;
use App\Monitoring\HealthSnapshot;
use App\Onboarding\Analysis;
use App\Onboarding\AnalysisStore;
use App\Onboarding\FirstRun;
use App\Onboarding\ProposalConfirmer;
use App\Scheduling\CloudRunAccessToken;
use App\Scheduling\Dispatcher;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/Onboarding/helpers.php';

function confirmationFixture(): array
{
    $engine = Engine::factory()->create();
    EnginePrice::factory()->create(['engine_key' => $engine->key, 'valid_from' => now()->subDay(), 'price_per_call_usd' => '1.00']);
    $plan = Plan::factory()->create(['allowed_engines' => [$engine->key], 'max_brands' => 10, 'max_markets_per_brand' => 3, 'max_frequency' => 'weekly', 'max_repetitions' => 1, 'monthly_call_quota' => 100, 'monthly_budget_usd' => '100']);
    [$tenant, $owner] = wizardTenant($plan);
    app(TenantContext::class)->set($tenant->id);
    $store = app(AnalysisStore::class);
    $analysis = $store->create($tenant->id, $owner->id, Analysis::BRAND, ['country' => 'NO', 'language' => 'nb']);
    $analysis->status = Analysis::READY;
    $store->save($analysis);
    $data = ['name' => 'Example', 'primary_domain' => 'example.test', 'country' => 'NO', 'language' => 'nb',
        'topics' => [['name' => 'Running', 'cross_market_key' => 'Running shoes']],
        'prompts' => [['text' => 'Which shoes are best?', 'cross_market_key' => 'Running shoes', 'intent' => 'category', 'funnel_stage' => 'awareness', 'priority' => 'core']],
        'engines' => [['engine_key' => $engine->key, 'frequency' => 'weekly', 'repetitions' => 1]]];

    return [$tenant, $owner, $analysis, $data];
}

test('an optional wizard step stores clickhouse and ga4 on the new brand', function () {
    [$tenant, $owner, $analysis, $data] = confirmationFixture();
    config(['ekko.bot_logs.clickhouse_endpoints' => ['https://logs.example.test']]);
    $data['clickhouse_host'] = 'https://logs.example.test';
    $data['clickhouse_database'] = 'logs';
    $data['clickhouse_table'] = 'hits';
    $data['clickhouse_user'] = 'reader';
    $data['clickhouse_password'] = 'wizard-secret';
    $data['ga4_property_id'] = '123456789';
    $data['ga4_client_email'] = 'ga4@example.test';
    $data['ga4_private_key'] = 'wizard-key';

    $this->actingAs($owner);
    $result = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));

    $stored = inTenant($tenant, fn () => Integration::query()->where('brand_id', $result['brand']->id)->get()->keyBy('type'));
    expect($stored['clickhouse_logs']->config['password'])->toBe('wizard-secret')
        ->and($stored['ga4']->config['property_id'])->toBe('123456789')
        ->and($stored['ga4']->config['service_account']['private_key'])->toBe('wizard-key');
});

test('stale ready proposals cannot confirm twice and topic keys normalize consistently', function () {
    [$tenant, , $analysis, $data] = confirmationFixture();
    $result = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    expect($result['market']->prompts()->firstOrFail()->topic->cross_market_key)->toBe('running_shoes');
    expect(fn () => inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data)))
        ->toThrow(ValidationException::class);
    expect(Brand::query()->count())->toBe(1)
        ->and(Run::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
    expect(inTenant($tenant, fn () => app(FirstRun::class)->start($tenant, $result['market'])))->toBe(0);
    expect(DB::table('jobs')->count())->toBe(1);
});

test('queue failure rolls back configuration runs and confirmation and permits a retry', function () {
    [$tenant, , $analysis, $data] = confirmationFixture();
    $queue = Queue::getFacadeRoot();
    Queue::shouldReceive('connection')->with('database')->once()->andReturnSelf();
    Queue::shouldReceive('push')->once()->andThrow(new RuntimeException('synthetic-queue-failure'));
    try {
        expect(fn () => inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data)))
            ->toThrow(RuntimeException::class, 'synthetic-queue-failure');
    } finally {
        Queue::swap($queue);
    }
    expect(Brand::query()->count())->toBe(0)->and(Run::query()->count())->toBe(0)
        ->and(app(AnalysisStore::class)->find($tenant->id, $analysis->id)->status)->toBe(Analysis::READY);
    inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    expect(Brand::query()->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
});

test('outer request rollback also rolls back confirmation and queued work', function () {
    [$tenant, , $analysis, $data] = confirmationFixture();
    DB::beginTransaction();
    inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    DB::rollBack();
    expect(Brand::query()->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0)
        ->and(app(AnalysisStore::class)->find($tenant->id, $analysis->id)->status)->toBe(Analysis::READY);
});

test('first collection respects the applicable quota and budget before enqueue', function (string $limit) {
    [$tenant, , $analysis, $data] = confirmationFixture();
    if ($limit === 'calls') {
        $tenant->forceFill(['monthly_call_quota_override' => 0])->save();
    } elseif ($limit === 'platform') {
        $tenant->plan->update(['monthly_budget_usd' => '0.50']);
    } else {
        $tenant->forceFill(['byok_enabled' => true, 'monthly_byok_budget_usd' => '0.50'])->save();
        TenantApiKey::query()->create(['provider' => 'openai', 'key' => 'synthetic-key', 'last_verified_at' => now()]);
        // An exhausted platform budget must not replace the independent BYOK budget.
        $tenant->plan->update(['monthly_budget_usd' => '0.00']);
    }
    $result = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    expect($result['runs'])->toBe(0)->and(Run::query()->sole()->status)->toBe('skipped_quota')
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['calls', 'platform', 'byok']);

test('market confirmation reuses the brand and existing topic', function () {
    [$tenant, $owner, $analysis, $data] = confirmationFixture();
    $saved = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    $store = app(AnalysisStore::class);
    $market = $store->create($tenant->id, $owner->id, Analysis::MARKET, ['brand_id' => $saved['brand']->id, 'country' => 'SE', 'language' => 'sv']);
    $market->status = Analysis::READY;
    $store->save($market);
    $data['country'] = 'SE';
    $data['language'] = 'sv';
    $result = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $market, $data));
    expect(Brand::query()->count())->toBe(1)->and($result['brand']->topics()->count())->toBe(1)
        ->and($result['brand']->markets()->count())->toBe(2)->and($result['market']->language)->toBe('sv')
        ->and($result['market']->prompts()->sole()->topic_id)->toBe($saved['market']->prompts()->sole()->topic_id);
});

test('the worker is launched only after the request transaction commits', function () {
    [$tenant, , $analysis, $data] = confirmationFixture();
    config(['collection.cloud_run.enabled' => true, 'collection.cloud_run.project' => 'ekko-fixture', 'collection.cloud_run.region' => 'europe-west1']);
    $token = Mockery::mock(CloudRunAccessToken::class);
    $token->shouldReceive('get')->andReturn('synthetic-token');
    app()->instance(CloudRunAccessToken::class, $token);
    Http::preventStrayRequests()->fake([
        '*/executions*' => Http::response(['executions' => []]),
        '*:run' => Http::response(['name' => 'operation']),
    ]);
    DB::beginTransaction();
    inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    Http::assertNothingSent();
    DB::commit();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/jobs/ekko-wake:run') && $request->body() === '{}');
});

test('BYOK collection can run with an exhausted platform budget', function () {
    [$tenant, , $analysis, $data] = confirmationFixture();
    $tenant->forceFill(['byok_enabled' => true, 'monthly_byok_budget_usd' => '2.00'])->save();
    $tenant->plan->update(['monthly_budget_usd' => '0.00']);
    TenantApiKey::query()->create(['provider' => 'openai', 'key' => 'synthetic-key', 'last_verified_at' => now()]);
    $result = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    expect($result['runs'])->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
});

test('the first batch includes due standard prompts and the dispatcher does not duplicate them', function () {
    [$tenant, , $analysis, $data] = confirmationFixture();
    $tenant->plan->update(['max_frequency' => 'daily']);
    $data['engines'][0]['frequency'] = 'daily';
    $data['prompts'][] = array_replace($data['prompts'][0], ['text' => 'Which shoes last longest?', 'priority' => 'standard']);
    $saved = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    expect($saved['runs'])->toBe(2);
    $dispatcher = app(Dispatcher::class);
    expect($dispatcher->dispatch(CarbonImmutable::now('UTC')))->toBe(0);
    inTenant($tenant, fn () => expect(Run::query()->count())->toBe(2));
    expect(DB::table('jobs')->count())->toBe(2);
});

test('the wizard page rejects readers and editors', function (string $role) {
    [, $user] = wizardTenant(role: $role);
    $this->actingAs($user)->get(route('onboarding.wizard'))->assertForbidden();
})->with(['reader', 'editor']);

test('an unpriced engine returns a recoverable validation error without saving the proposal', function () {
    [$tenant, $owner, $analysis, $data] = confirmationFixture();
    EnginePrice::query()->where('engine_key', $data['engines'][0]['engine_key'])->delete();
    $this->actingAs($owner)->postJson(route('onboarding.analyses.confirm', $analysis->id), $data)
        ->assertUnprocessable()->assertJsonValidationErrors('engines.0.engine_key');
    expect(Brand::query()->count())->toBe(0)->and(Run::query()->count())->toBe(0)
        ->and(app(AnalysisStore::class)->find($tenant->id, $analysis->id)->status)->toBe(Analysis::READY);
});

test('confirmation rejects excess core prompts instead of silently changing the plan', function () {
    [$tenant, $owner, $analysis, $data] = confirmationFixture();
    config(['ekko.wizard.suggestions.max_core_prompts' => 1]);
    $data['prompts'][] = array_replace($data['prompts'][0], ['text' => 'Which shoes are most durable?']);
    $this->actingAs($owner)->postJson(route('onboarding.analyses.confirm', $analysis->id), $data)
        ->assertUnprocessable()->assertJsonValidationErrors('prompts');
    expect(Brand::query()->count())->toBe(0)->and(Run::query()->count())->toBe(0)
        ->and(app(AnalysisStore::class)->find($tenant->id, $analysis->id)->status)->toBe(Analysis::READY);
});

test('market confirmation cannot relabel analysed prompts with another country or language', function (string $field, string $value) {
    [$tenant, $owner, $analysis, $data] = confirmationFixture();
    $saved = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    $store = app(AnalysisStore::class);
    $market = $store->create($tenant->id, $owner->id, Analysis::MARKET, ['brand_id' => $saved['brand']->id, 'country' => 'SE', 'language' => 'sv']);
    $market->status = Analysis::READY;
    $store->save($market);
    $data['country'] = 'SE';
    $data['language'] = 'sv';
    $data[$field] = $value;
    $this->actingAs($owner)->postJson(route('onboarding.analyses.confirm', $market->id), $data)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($saved['brand']->markets()->count())->toBe(1)
        ->and($store->find($tenant->id, $market->id)->status)->toBe(Analysis::READY);
})->with([['country', 'DE'], ['language', 'de']]);

test('market analysis rejects an existing locale before dispatching work', function () {
    [$tenant, $owner, $analysis, $data] = confirmationFixture();
    $saved = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    Queue::fake();
    $this->actingAs($owner)->postJson(route('brands.market-proposals.store', $saved['brand']), ['country' => 'no', 'language' => 'NB'])
        ->assertUnprocessable()->assertJsonValidationErrors('country');
    Queue::assertNothingPushed();
});

test('confirmation rejects a duplicate locale from existing markets or competing analyses', function (bool $competing) {
    [$tenant, $owner, $analysis, $data] = confirmationFixture();
    $saved = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    $store = app(AnalysisStore::class);
    $data['country'] = $competing ? 'SE' : 'NO';
    $data['language'] = $competing ? 'sv' : 'nb';
    $proposals = [];
    foreach (range(1, $competing ? 2 : 1) as $index) {
        $proposal = $store->create($tenant->id, $owner->id, Analysis::MARKET, ['brand_id' => $saved['brand']->id, 'country' => $data['country'], 'language' => $data['language']]);
        $proposal->status = Analysis::READY;
        $store->save($proposal);
        $proposals[] = $proposal;
    }
    if ($competing) {
        inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $proposals[0], $data));
    }
    $jobs = DB::table('jobs')->count();
    $runs = Run::query()->count();
    $rejected = $proposals[count($proposals) - 1];
    $this->actingAs($owner)->postJson(route('onboarding.analyses.confirm', $rejected->id), $data)
        ->assertUnprocessable()->assertJsonValidationErrors('country');
    expect($saved['brand']->markets()->count())->toBe($competing ? 2 : 1)
        ->and(Run::query()->count())->toBe($runs)
        ->and(DB::table('jobs')->count())->toBe($jobs)
        ->and($store->find($tenant->id, $rejected->id)->status)->toBe(Analysis::READY);
})->with([false, true]);

test('brand confirmation cannot change the analysed locale', function (string $field, string $value) {
    [$tenant, $owner, $analysis, $data] = confirmationFixture();
    $data[$field] = $value;
    $this->actingAs($owner)->postJson(route('onboarding.analyses.confirm', $analysis->id), $data)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Brand::query()->count())->toBe(0)->and(Run::query()->count())->toBe(0)
        ->and(app(AnalysisStore::class)->find($tenant->id, $analysis->id)->status)->toBe(Analysis::READY);
})->with([['country', 'SE'], ['language', 'sv']]);

test('enabling an engine after an empty first run can dispatch on the same day', function () {
    [$tenant, , $analysis, $data] = confirmationFixture();
    $tenant->plan->update(['max_frequency' => 'daily']);
    $engine = $data['engines'][0]['engine_key'];
    $data['engines'] = [];
    $saved = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    expect($saved['runs'])->toBe(0)->and(RunBatch::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
    MarketEngine::factory()->for($saved['market'])->create(['engine_key' => $engine, 'prompt_scope' => 'all', 'frequency' => 'daily', 'repetitions' => 1]);
    expect(app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC')))->toBe(1);
    inTenant($tenant, fn () => expect(RunBatch::query()->count())->toBe(1)->and(Run::query()->count())->toBe(1));
    expect(DB::table('jobs')->count())->toBe(1);
});

test('immediate onboarding batches age from confirmation rather than local midnight', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 14:00:00', 'UTC'));
    [$tenant, , $analysis, $data] = confirmationFixture();
    $tenant->forceFill(['timezone' => 'Europe/Oslo'])->save();
    $saved = inTenant($tenant, fn () => app(ProposalConfirmer::class)->confirm($tenant, $analysis, $data));
    $batch = inTenant($tenant, fn () => RunBatch::query()->where('market_id', $saved['market']->id)->sole());
    expect($batch->scheduled_for->utc()->toDateTimeString())->toBe('2026-11-17 14:00:00')
        ->and($batch->dispatch_date)->toBe('2026-11-17');
    $health = app(HealthSnapshot::class);
    expect($health->collect(CarbonImmutable::now('UTC'))['collection_overdue'])->toBe(0)
        ->and($health->collect(CarbonImmutable::now('UTC')->addHours(6))['collection_overdue'])->toBe(0)
        ->and($health->collect(CarbonImmutable::now('UTC')->addHours(6)->addSecond())['collection_overdue'])->toBe(1);
});
