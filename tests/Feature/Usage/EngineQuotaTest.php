<?php

use App\Engines\Credentials\ApiKeyProvider;
use App\Engines\Credentials\TenantApiKeys;
use App\Engines\EngineCallFailed;
use App\Engines\EngineCollector;
use App\Engines\RawResponseStore;
use App\Jobs\CollectEngineRun;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Engines\EngineFixtures;

test('a queued engine run uses the tenant key, releases the transaction and respects the BYOK budget', function () {
    $this->travelTo(now()->parse('2026-11-15T12:00:00Z'));
    $fixture = EngineFixtures::load('chatgpt_api', 'no-nb');
    $plan = Plan::factory()->create(['monthly_budget_usd' => '0.00']);
    $tenant = Team::factory()->tenant()->withPlan($plan)->create([
        'byok_enabled' => true,
        'monthly_byok_budget_usd' => '1.00',
    ]);
    $other = Team::factory()->tenant()->create();
    $depth = DB::transactionLevel();
    Http::preventStrayRequests()->fake([
        'https://api.openai.com/v1/models' => Http::response(['data' => []]),
        'https://api.openai.com/v1/responses' => function () use ($tenant, $depth, $fixture) {
            expect(current_tenant_id())->toBe($tenant->id)
                ->and(DB::transactionLevel())->toBe($depth)
                ->and(app(TenantRls::class)->currentSettings()['tenant_ids'])->toBe('');

            return Http::response($fixture);
        },
    ]);
    $rawStore = Mockery::mock(RawResponseStore::class);
    $rawStore->expects('put')->once()->andReturn('gs://fixture/collected.json');
    app()->instance(RawResponseStore::class, $rawStore);

    $engine = quotaEngine();
    $context = app(TenantContext::class);
    $rls = app(TenantRls::class);
    [$first, $next] = $context->run($tenant->id, fn () => $rls->run([$tenant->id], function () use ($tenant, $engine) {
        app(TenantApiKeys::class)->store($tenant->owner, ApiKeyProvider::OpenAi, 'verified-test-key');

        return quotaRuns($engine);
    }));

    expect(fn () => dispatch_sync(new CollectEngineRun($other->id, $first->id)))
        ->toThrow(ModelNotFoundException::class);
    Http::assertSentCount(1);

    dispatch_sync(new CollectEngineRun($tenant->id, $first->id));
    dispatch_sync(new CollectEngineRun($tenant->id, $next->id));
    expect($context->check())->toBeFalse();

    $context->run($tenant->id, fn () => $rls->run([$tenant->id], function () use ($first, $next) {
        expect(UsageEvent::query()->sole()->billed_to)->toBe('byok')
            ->and($first->refresh()->status)->toBe('collected')
            ->and($next->refresh()->status)->toBe('skipped_quota');
    }));

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
        && $request->hasHeader('Authorization', 'Bearer verified-test-key'));
});

function quotaRuns(Engine $engine): iterable
{
    $market = Market::factory()->create(['country' => 'NO', 'language' => 'nb']);
    $prompt = Prompt::factory()->for($market)->create(['priority' => 'core']);
    $batch = RunBatch::factory()->for($market)->create();

    return Run::factory()->for($batch, 'runBatch')->for($prompt)->for($engine)->createMany([
        ['repetition' => 1],
        ['repetition' => 2],
    ]);
}

function quotaEngine(): Engine
{
    $engine = Engine::factory()->create([
        'key' => 'chatgpt_api',
        'method' => 'api',
        'provider' => 'openai',
        'is_enabled' => true,
    ]);
    EnginePrice::factory()->for($engine)->create([
        'valid_from' => now()->subDay(),
        'price_per_call_usd' => '1.00',
        'price_per_1k_input_tokens_usd' => '0',
        'price_per_1k_output_tokens_usd' => '0',
        'price_per_search_usd' => '0',
    ]);

    return $engine;
}

test('a second run can enter while the first provider call is in progress if quota allows', function (int $limit) {
    $this->travelTo(now()->parse('2026-11-15T12:00:00Z'));
    $fixture = EngineFixtures::load('chatgpt_api', 'no-nb');
    $tenant = Team::factory()->tenant()->withPlan(Plan::factory()->create(['monthly_call_quota' => $limit]))->create();
    $engine = quotaEngine();
    $rls = app(TenantRls::class);
    $depth = DB::transactionLevel();
    $calls = 0;

    $raw = Mockery::mock(RawResponseStore::class);
    $raw->expects('put')->times($limit)->andReturn('gs://fixture/collected.json');
    app()->instance(RawResponseStore::class, $raw);

    app(TenantContext::class)->run($tenant->id, function () use ($tenant, $engine, $limit, $depth, $fixture, $rls, &$calls) {
        [$first, $second] = $rls->run([$tenant->id], fn () => quotaRuns($engine));
        $collector = app(EngineCollector::class);
        Http::preventStrayRequests()->fake([
            'https://api.openai.com/v1/responses' => function () use ($first, $second, $collector, $depth, $fixture, $limit, &$calls) {
                expect(DB::transactionLevel())->toBe($depth);

                if (++$calls === 1) {
                    // Even after the grace period, a live advisory lock protects the first call.
                    $this->travel(10)->minutes();
                    expect(fn () => $collector->collect($first->id, 'synthetic-key'))
                        ->toThrow(EngineCallFailed::class, 'run_already_reserved');
                    expect($collector->collect($second->id, 'synthetic-key') !== null)->toBe($limit === 2);
                }

                return Http::response($fixture);
            },
        ]);

        expect($collector->collect($first->id, 'synthetic-key'))->not->toBeNull();
        $rls->run([$tenant->id], function () use ($limit, $first, $second, $collector) {
            expect(UsageEvent::query()->count())->toBe($limit)
                ->and($first->refresh()->quota_reservation_token)->toBeNull()
                ->and($second->refresh()->status)->toBe($limit === 2 ? 'collected' : 'skipped_quota')
                ->and(fn () => $collector->collect($first->id))->toThrow(LogicException::class);
        });
    });

    expect($calls)->toBe($limit);
})->with(['one call left' => 1, 'two calls left' => 2]);

test('a handled provider failure releases its reservation so a retry can use the quota', function () {
    $this->travelTo(now()->parse('2026-11-15T12:00:00Z'));
    Http::preventStrayRequests()->fake([
        'https://api.openai.com/v1/responses' => Http::sequence()->push([], 422)->push(EngineFixtures::load('chatgpt_api', 'no-nb')),
    ]);
    $tenant = Team::factory()->tenant()->withPlan(Plan::factory()->create(['monthly_call_quota' => 1]))->create();
    $engine = quotaEngine();
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->expects('put')->once()->andReturn('gs://fixture/collected.json');
    app()->instance(RawResponseStore::class, $raw);

    app(TenantContext::class)->run($tenant->id, function () use ($tenant, $engine) {
        $rls = app(TenantRls::class);
        [$run] = $rls->run([$tenant->id], fn () => quotaRuns($engine));
        $collector = app(EngineCollector::class);

        expect(fn () => $collector->collect($run->id, 'synthetic-key'))
            ->toThrow(EngineCallFailed::class, 'openai_http_422');
        $rls->run([$tenant->id], fn () => expect($run->refresh()->quota_reservation_token)->toBeNull());

        expect($collector->collect($run->id, 'synthetic-key'))->not->toBeNull();
        $rls->run([$tenant->id], fn () => expect(UsageEvent::query()->count())->toBe(1));
    });

    Http::assertSentCount(2);
});

test('a disconnected worker retains its reservation until the grace period expires', function () {
    $this->travelTo(now()->parse('2026-11-15T12:00:00Z'));
    Http::preventStrayRequests()->fake([
        'https://api.openai.com/v1/responses' => Http::response(EngineFixtures::load('chatgpt_api', 'no-nb')),
    ]);
    $tenant = Team::factory()->tenant()->withPlan(Plan::factory()->create(['monthly_call_quota' => 1]))->create();
    $engine = quotaEngine();
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->expects('put')->once()->andReturn('gs://fixture/collected.json');
    app()->instance(RawResponseStore::class, $raw);

    app(TenantContext::class)->run($tenant->id, function () use ($tenant, $engine) {
        $rls = app(TenantRls::class);
        [$run, $other] = $rls->run([$tenant->id], function () use ($engine) {
            [$run, $other] = quotaRuns($engine);
            $run->forceFill([
                'quota_reservation_token' => (string) Str::uuid(),
                'quota_reserved_at' => now(),
                'quota_reserved_cost_usd' => '1.00',
                'quota_reserved_billed_to' => 'byok',
            ])->save();

            return [$run, $other];
        });
        $collector = app(EngineCollector::class);

        expect(fn () => $collector->collect($run->id, 'synthetic-key'))
            ->toThrow(EngineCallFailed::class, 'run_already_reserved')
            ->and($collector->collect($other->id, 'synthetic-key'))->toBeNull();
        Http::assertNothingSent();

        $this->travel(10)->minutes();

        expect($collector->collect($run->id, 'synthetic-key'))->not->toBeNull();
        $rls->run([$tenant->id], fn () => expect(UsageEvent::query()->count())->toBe(1));
    });

    Http::assertSentCount(1);
});
