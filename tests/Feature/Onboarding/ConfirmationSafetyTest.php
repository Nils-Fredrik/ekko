<?php

use App\Models\Brand;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Plan;
use App\Models\Run;
use App\Models\TenantApiKey;
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
    $analysis = $store->create($tenant->id, $owner->id, Analysis::BRAND, []);
    $analysis->status = Analysis::READY;
    $store->save($analysis);
    $data = ['name' => 'Example', 'primary_domain' => 'example.test', 'country' => 'NO', 'language' => 'nb',
        'topics' => [['name' => 'Running', 'cross_market_key' => 'Running shoes']],
        'prompts' => [['text' => 'Which shoes are best?', 'cross_market_key' => 'Running shoes', 'intent' => 'category', 'funnel_stage' => 'awareness', 'priority' => 'core']],
        'engines' => [['engine_key' => $engine->key, 'frequency' => 'weekly', 'repetitions' => 1]]];

    return [$tenant, $owner, $analysis, $data];
}

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
    Http::assertSent(fn ($request) => str_ends_with($request->url(), ':run'));
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
