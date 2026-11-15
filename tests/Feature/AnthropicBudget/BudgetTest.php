<?php

use App\AnthropicBudget\Account;
use App\AnthropicBudget\BudgetedExtractionClient;
use App\AnthropicBudget\CreditExhausted;
use App\AnthropicBudget\Estimator;
use App\AnthropicBudget\Money;
use App\Engines\Adapters\ClaudeHaikuAdapter;
use App\Engines\EngineCollector;
use App\Engines\EngineRequest;
use App\Engines\RawResponseStore;
use App\Extraction\AnthropicExtractionClient;
use App\Extraction\ExtractionRequest;
use App\Jobs\SubmitExtractionBatch;
use App\Models\AnthropicBudgetPlan;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\User;
use App\Scheduling\Dispatcher;
use App\Scheduling\PromptSchedule;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Engines\EngineFixtures;
use Tests\Support\Extraction\FakeHttpClient;

function inAnthropicTenant(Team $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], $callback));
}

beforeEach(function () {
    config(['ekko.anthropic.monthly_pool_usd' => '100', 'ekko.anthropic.cycle_start_day' => 15]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
});

test('account includes platform extraction and onboarding, excludes BYOK and survives tenant deletion', function () {
    $tenant = Team::factory()->tenant()->create();
    inAnthropicTenant($tenant, function () {
        UsageEvent::factory()->create(['kind' => 'extraction', 'engine_key' => null, 'cost_usd' => '90', 'billed_to' => 'platform', 'occurred_at' => now()]);
        UsageEvent::factory()->create(['kind' => 'onboarding', 'engine_key' => null, 'cost_usd' => '5', 'billed_to' => 'platform', 'occurred_at' => now()]);
        UsageEvent::factory()->create(['kind' => 'extraction', 'engine_key' => null, 'cost_usd' => '99', 'billed_to' => 'byok', 'occurred_at' => now()]);
        UsageEvent::factory()->create(['kind' => 'extraction', 'engine_key' => null, 'cost_usd' => '99', 'billed_to' => 'platform', 'occurred_at' => now()->subMonth()]);
    });
    $account = app(Account::class);
    expect($account->summary()['spent_usd'])->toBe('95.00000000')->and($account->summary()['reduced'])->toBeTrue();
    $tenant->delete();
    expect($account->summary()['spent_usd'])->toBe('95.00000000');
    $this->travelTo(CarbonImmutable::parse('2026-12-15'));
    expect($account->summary()['reduced'])->toBeFalse()->and($account->summary()['spent_usd'])->toBe('0.00000000');
});

test('pending admissions cannot share capacity and exhausted credit resets only in a new cycle', function () {
    $account = app(Account::class);
    expect($account->reserve('first', Money::units('60'), false))->toBeTrue()
        ->and($account->reserve('second', Money::units('50'), false))->toBeFalse();
    $account->settle('first');
    expect($account->reserve('second', Money::units('50'), false))->toBeTrue();
    $account->markExhausted();
    expect($account->reserve('core', 1, true))->toBeFalse()->and($account->summary()['exhausted'])->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2026-12-15'));
    expect($account->reserve('new-cycle', Money::units('100'), true))->toBeTrue()->and($account->summary()['exhausted'])->toBeFalse();
});

test('the provider credit error changes only the platform account and contains no provider body', function () {
    Engine::factory()->create(['key' => 'claude_haiku', 'provider' => 'anthropic']);
    config(['services.anthropic.key' => 'synthetic-key']);
    Http::preventStrayRequests()->fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.']], 400)]);
    expect(fn () => app(ClaudeHaikuAdapter::class)->collect(new EngineRequest('Synthetic prompt', 'NO', 'nb', null, '1', 'synthetic-byok')))->toThrow('anthropic_credits_exhausted');
    expect(app(Account::class)->summary()['exhausted'])->toBeFalse();
    expect(fn () => app(ClaudeHaikuAdapter::class)->collect(new EngineRequest('Synthetic prompt', 'NO', 'nb', null, '1')))->toThrow('anthropic_credits_exhausted');
    expect(app(Account::class)->summary()['exhausted'])->toBeTrue();
    Http::assertSentCount(2);
});

test('planning feeds additional Sonnet calls into idempotent dispatch and isolates tenant plans', function () {
    $engine = Engine::factory()->create(['key' => 'claude_sonnet', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subYear(), 'price_per_call_usd' => '0.02']);
    $plan = Plan::factory()->create(['allowed_engines' => ['claude_sonnet'], 'max_repetitions' => 3]);
    $tenant = Team::factory()->tenant()->withPlan($plan)->create();
    $prompt = inAnthropicTenant($tenant, function () use ($engine) {
        $market = Market::factory()->create(['is_active' => true]);
        $market->brand->update(['status' => 'active']);
        MarketEngine::factory()->for($market)->for($engine)->create(['frequency' => 'weekly', 'repetitions' => 1]);

        return Prompt::factory()->for($market)->create(['is_active' => true, 'priority' => 'core']);
    });
    while (PromptSchedule::due($prompt->id, 'weekly', CarbonImmutable::now()->setTimezone($tenant->timezone))) {
        $this->travel(1)->days();
    }
    expect(app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC')))->toBe(1);
    expect(app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC')))->toBe(0);
    inAnthropicTenant($tenant, function () {
        expect(Run::query()->count())->toBe(1)->and(Run::query()->sole()->engine_key)->toBe('claude_sonnet')
            ->and(AnthropicBudgetPlan::query()->count())->toBe(1);
    });
    $other = Team::factory()->tenant()->create();
    inAnthropicTenant($other, fn () => expect(AnthropicBudgetPlan::query()->count())->toBe(0));
});

test('admin usage is available only to the platform admin and has translated budget state', function () {
    $user = User::factory()->platformAdmin()->withPersonalTeam()->withTwoFactor()->create();
    $this->actingAs($user)->get(route('admin.usage'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Usage')->where('anthropic.pool_usd', '100.00000000'));
    $this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('admin.usage'))->assertForbidden();
});

test('pending extraction reserves the shared account and a credit rejection survives the job rollback', function () {
    config(['services.anthropic.key' => 'synthetic-key']);
    $http = new FakeHttpClient(['POST /v1/messages/batches' => [200, FakeHttpClient::batch('in_progress')]]);
    $inner = AnthropicExtractionClient::fromConfig($http);
    $client = new BudgetedExtractionClient($inner, app(Account::class), app(Estimator::class));
    expect($client->createBatch([new ExtractionRequest('r1', 'System', 'Synthetic answer')]))->toBe('msgbatch_1');
    expect(DB::table('anthropic_budget_holds')->where('operation', 'extract:r1')->exists())->toBeTrue();
    config(['ekko.anthropic.monthly_pool_usd' => '0.0001']);
    expect(fn () => $client->createBatch([new ExtractionRequest('r2', 'System', 'Synthetic answer')]))->toThrow('anthropic_budget_unavailable');
    expect($http->requests)->toHaveCount(1);
    config(['ekko.anthropic.monthly_pool_usd' => '100']);
    $failedHttp = new FakeHttpClient(['POST /v1/messages/batches' => [400, json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.']], JSON_THROW_ON_ERROR)]]);
    $failed = new BudgetedExtractionClient(AnthropicExtractionClient::fromConfig($failedHttp), app(Account::class), app(Estimator::class));
    expect(fn () => DB::transaction(fn () => $failed->createBatch([new ExtractionRequest('r3', 'System', 'Synthetic answer')])))->toThrow(CreditExhausted::class);
    expect(app(Account::class)->summary()['exhausted'])->toBeFalse();
    (new SubmitExtractionBatch(1))->failed(new CreditExhausted);
    expect(app(Account::class)->summary()['exhausted'])->toBeTrue();
});

test('reduced mode skips standard prompts before HTTP while a core Haiku run settles real usage', function () {
    $engine = Engine::factory()->create(['key' => 'claude_haiku', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subYear(), 'price_per_call_usd' => '0.02']);
    $tenant = Team::factory()->tenant()->withPlan(Plan::factory()->create(['allowed_engines' => ['claude_haiku']]))->create();
    [$core, $standard] = inAnthropicTenant($tenant, function () use ($engine) {
        UsageEvent::factory()->create(['kind' => 'extraction', 'engine_key' => null, 'cost_usd' => '95', 'billed_to' => 'platform', 'occurred_at' => now()]);
        $core = Run::factory()->for($engine)->create();
        $core->prompt->update(['priority' => 'core']);
        $standard = Run::factory()->for($engine)->create();
        $standard->prompt->update(['priority' => 'standard']);

        return [$core, $standard];
    });
    config(['services.anthropic.key' => 'synthetic-key']);
    Http::preventStrayRequests()->fake(['api.anthropic.com/*' => Http::response(EngineFixtures::load('claude', 'no-nb'))]);
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->expects('put')->once()->andReturn('gs://synthetic/account-test.json');
    app()->instance(RawResponseStore::class, $raw);
    app(TenantContext::class)->run($tenant->id, function () use ($core, $standard) {
        expect(app(EngineCollector::class)->collect($standard->id))->toBeNull();
        expect(app(EngineCollector::class)->collect($core->id))->not->toBeNull();
    });
    Http::assertSentCount(1);
    inAnthropicTenant($tenant, fn () => expect($standard->fresh()->status)->toBe('skipped_quota')->and($core->fresh()->status)->toBe('collected'));
    expect(DB::table('anthropic_budget_holds')->count())->toBe(0)->and(app(Account::class)->summary()['spent_usd'])->toBe('95.02000000');
});
