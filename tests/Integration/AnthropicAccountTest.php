<?php

use App\AnthropicBudget\Account;
use App\AnthropicBudget\BudgetedExtractionClient;
use App\AnthropicBudget\CreditExhausted;
use App\AnthropicBudget\Estimator;
use App\AnthropicBudget\Money;
use App\Extraction\AnthropicExtractionClient;
use App\Extraction\ExtractionClient;
use App\Extraction\ExtractionOutcome;
use App\Extraction\ExtractionRequest;
use App\Extraction\ExtractionRunner;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\Extraction\FakeHttpClient;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function () {
    config(['ekko.anthropic.monthly_pool_usd' => '100', 'ekko.anthropic.cycle_start_day' => 15]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
});

afterEach(function () {
    DB::purge('anthropic_budget');
});

test('budget commits do not run the outer job callbacks before the tenant commits', function () {
    $committed = false;
    expect(fn () => DB::transaction(function () use (&$committed) {
        DB::afterCommit(function () use (&$committed) {
            $committed = true;
        });
        Account::independent()->reserve('callback-isolation', Money::units('1'), true);
        expect($committed)->toBeFalse();
        throw new RuntimeException('caller_rollback');
    }))->toThrow('caller_rollback');
    expect($committed)->toBeFalse();
});

test('provider admission commits before HTTP and survives the outer job rollback', function () {
    $inner = Mockery::mock(ExtractionClient::class);
    $inner->expects('createBatch')->andReturnUsing(function () {
        $independent = Account::independent();
        expect($independent->db()->transactionLevel())->toBe(0)
            ->and($independent->reserve('other-tenant', Money::units('1'), true))->toBeTrue();

        return 'accepted-batch';
    });
    $client = new BudgetedExtractionClient($inner, app(Account::class), app(Estimator::class));
    expect(fn () => DB::transaction(function () use ($client) {
        expect($client->createBatch([new ExtractionRequest('r1', 'system', 'answer')]))->toBe('accepted-batch');
        throw new RuntimeException('caller_rollback');
    }))->toThrow('caller_rollback');
    expect(DB::table('anthropic_budget_holds')->where('provider_batch_id', 'accepted-batch')->whereNull('settled_at')->count())->toBe(1);
});

test('a known batch outcome cannot release the independent hold of an unknown retry', function () {
    $inner = Mockery::mock(ExtractionClient::class);
    $inner->expects('createBatch')->andReturn('batch');
    $inner->shouldReceive('batchResults')->twice()->andReturn([new ExtractionOutcome('r1', true, 'invalid JSON', 10, 5, true)]);
    $inner->expects('message')->andThrow(new RuntimeException('unknown_transport_outcome'));
    $client = new BudgetedExtractionClient($inner, app(Account::class), app(Estimator::class));
    $request = new ExtractionRequest('r1', 'system', 'answer');
    $client->createBatch([$request]);
    $attempt = (new ExtractionRunner($client))->collect('batch', ['r1'], fn () => $request)['r1'];
    expect($attempt->failed())->toBeTrue()->and($attempt->outcomes)->toHaveCount(1)
        ->and(DB::table('anthropic_budget_holds')->whereNull('settled_at')->count())->toBe(1)
        ->and(DB::table('anthropic_credit_spend')->count())->toBe(1);
    $tenant = Team::factory()->tenant()->create();
    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], fn () => UsageEvent::factory()->create([
        'kind' => 'extraction', 'engine_key' => null, 'billed_to' => 'platform', 'occurred_at' => now(),
        'cost_usd' => '0.00000175', 'anthropic_operation' => $attempt->outcomes[0]->budgetOperation,
    ])));
    iterator_to_array($client->batchResults('batch'));
    expect(DB::table('anthropic_credit_spend')->count())->toBe(1)
        ->and(app(Account::class)->spent(app(Account::class)->cycle()))->toBe(Money::units('0.00000175'))
        ->and(DB::table('anthropic_budget_holds')->whereNull('settled_at')->count())->toBe(1);
});

test('known zero-token terminal results release capacity even without a surviving response', function () {
    $inner = Mockery::mock(ExtractionClient::class);
    $inner->expects('createBatch')->andReturn('batch-zero');
    $inner->expects('batchResults')->andReturn([new ExtractionOutcome('r99', false, null, 0, 0, true, 'canceled')]);
    $client = new BudgetedExtractionClient($inner, app(Account::class), app(Estimator::class));
    $client->createBatch([new ExtractionRequest('r99', 'system', 'answer')]);
    iterator_to_array($client->batchResults('batch-zero'));
    expect(DB::table('anthropic_budget_holds')->whereNull('settled_at')->count())->toBe(0)
        ->and(app(Account::class)->spent(app(Account::class)->cycle()))->toBe(0);
});

test('admission retries against the new exhausted cycle after crossing a boundary while locking', function () {
    DB::table('anthropic_budget_cycles')->insert(['cycle_start' => '2026-12-15', 'exhausted' => true]);
    $moved = false;
    DB::listen(function ($query) use (&$moved) {
        if (! $moved && str_contains(strtolower($query->sql), 'for update')) {
            $moved = true;
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-15T00:00:00Z'));
            Carbon::setTestNow(CarbonImmutable::parse('2026-12-15T00:00:00Z'));
        }
    });
    expect(app(Account::class)->reserve('boundary', 1, true))->toBeFalse()
        ->and(DB::table('anthropic_budget_holds')->count())->toBe(0);
});

test('an HTTP credit rejection refunds only its attempt and persists the stop across job rollback', function () {
    config(['services.anthropic.key' => 'synthetic-key']);
    $http = new FakeHttpClient(['POST /v1/messages/batches' => [400, json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.']], JSON_THROW_ON_ERROR)]]);
    $client = new BudgetedExtractionClient(AnthropicExtractionClient::fromConfig($http), app(Account::class), app(Estimator::class));
    expect(fn () => DB::transaction(fn () => $client->createBatch([new ExtractionRequest('r1', 'system', 'answer')])))->toThrow(CreditExhausted::class);
    expect(app(Account::class)->summary()['exhausted'])->toBeTrue()
        ->and(DB::table('anthropic_budget_holds')->whereNull('settled_at')->count())->toBe(0)
        ->and($http->requests)->toHaveCount(1);
});
