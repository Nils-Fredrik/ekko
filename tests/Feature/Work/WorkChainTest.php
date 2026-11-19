<?php

use Anthropic\Core\Exceptions\APIConnectionException;
use App\Engines\EngineCollector;
use App\Engines\EngineRegistry;
use App\Engines\QueuedEngineCollector;
use App\Engines\RawResponseStore;
use App\Extraction\AnthropicExtractionClient;
use App\Extraction\ExtractionClient;
use App\Extraction\ExtractionWriter;
use App\Jobs\CollectEngineRun;
use App\Jobs\PollExtractionBatch;
use App\Jobs\SubmitExtractionBatch;
use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\ExtractionJob;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\UsageMonthly;
use App\Models\WorkSignal;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Work\Handlers\AggregationWorkHandler;
use App\Work\Handlers\ExtractionWorkHandler;
use App\Work\WorkChain;
use App\Work\WorkClaim;
use App\Work\WorkKind;
use App\Work\WorkProcessor;
use App\Work\WorkPublisher;
use App\Work\WorkResult;
use App\Work\WorkScope;
use App\Work\WorkSignals;
use App\Work\WorkWakeUp;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\Engines\EngineFixtures;
use Tests\Support\Extraction\FakeExtractionClient;
use Tests\Support\Extraction\FakeHttpClient;
use Tests\Support\Work\ChainEngineAdapter;
use Tests\Support\Work\ChainExtractionClient;
use Tests\Support\Work\ChainRawStore;
use Tests\Support\Work\RecordingWorkPublisher;

// Chained processing (docs/spec.md 7.1, ADR 0032, T40-F3): a finished collection registers extraction, a stored
// extraction result registers the aggregation of its local day, and every usage event the roll-up of its month.
// Only fakes and fixtures: no paid model or engine calls.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC'));
    config([
        'ekko.work.chaining' => true,
        'ekko.work.extraction.group_wait_seconds' => 600,
        'ekko.work.extraction.status_first_seconds' => 60,
        'ekko.work.extraction.status_max_seconds' => 900,
        'ekko.work.extraction.status_jitter_seconds' => 0,
        'ekko.work.extraction.status_give_up_hours' => 25,
        'ekko.work.retry_jitter_seconds' => 0,
        'ekko.anthropic.extraction.batch_size' => 100,
    ]);
    $this->publisher = new RecordingWorkPublisher;
    app()->instance(WorkPublisher::class, $this->publisher);
    app()->forgetScopedInstances();

    $this->adapter = new ChainEngineAdapter('chain_engine');
    $this->slow = new ChainEngineAdapter('chain_slow');
    app()->instance(EngineRegistry::class, new EngineRegistry([$this->adapter, $this->slow]));
    app()->instance(RawResponseStore::class, new ChainRawStore);
    foreach (['chain_engine', 'chain_slow'] as $key) {
        $engine = Engine::factory()->create(['key' => $key, 'is_enabled' => true]);
        EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subYear(), 'price_per_call_usd' => '0.01000000', 'price_per_1k_input_tokens_usd' => 0, 'price_per_1k_output_tokens_usd' => 0, 'price_per_search_usd' => 0]);
    }

    $this->extraction = new ChainExtractionClient(new FakeExtractionClient(fn (string $id): array => [
        'batch' => ['text' => ['mentions' => [], 'citations' => [], 'answer_type' => 'list'], 'input_tokens' => 120, 'output_tokens' => 30],
    ]));
    app()->instance(ExtractionClient::class, $this->extraction);

    // A tenant with a brand, a competitor and a market, and a collection group (run batch) of runs.
    $this->world = function (string $timezone = 'Europe/Oslo', array $engines = ['chain_engine', 'chain_engine'], ?Plan $plan = null): array {
        $factory = Team::factory()->tenant();
        $tenant = ($plan === null ? $factory : $factory->withPlan($plan))->create(['timezone' => $timezone]);

        return app(TenantContext::class)->run($tenant->id, function () use ($tenant, $engines): array {
            $brand = Brand::factory()->create(['name' => 'Fjordkaffe']);
            $brand->aliases()->create(['alias' => 'Fjordkaffe', 'match_mode' => 'case_insensitive']);
            $competitor = Competitor::factory()->for($brand)->create();
            $competitor->aliases()->create(['alias' => 'Nordlys Rosteri', 'match_mode' => 'case_insensitive']);
            $market = Market::factory()->for($brand)->create(['language' => 'nb', 'country' => 'NO']);
            $batch = RunBatch::factory()->for($market)->create();
            $runs = [];
            foreach ($engines as $engineKey) {
                $prompt = Prompt::factory()->for($market)->create();
                $runs[] = Run::factory()->for($batch, 'runBatch')->for($prompt)->create(['engine_key' => $engineKey]);
            }

            return ['tenant' => $tenant, 'market' => $market, 'batch' => $batch, 'runs' => $runs];
        });
    };

    $this->inTenant = fn (Team $tenant, Closure $callback): mixed => app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], $callback));
    $this->collect = fn (Team $tenant, Run $run) => app(TenantContext::class)->run($tenant->id, fn () => app(EngineCollector::class)->collect($run->id));
    $this->signals = fn (Team $tenant): array => ($this->inTenant)($tenant, fn () => WorkSignal::query()->orderBy('id')->get()
        ->mapWithKeys(fn (WorkSignal $signal): array => [$signal->kind->value.':'.$signal->scope => $signal])->all());

    // Delivers every published wake-up that is due, as ProcessWorkSignal would, until nothing due is left.
    $this->drain = function (): array {
        $statuses = [];
        for ($round = 0; $round < 50; $round++) {
            $due = array_values(array_filter($this->publisher->published, fn (WorkWakeUp $wakeUp): bool => ! $wakeUp->notBefore->isFuture()));
            if ($due === []) {
                break;
            }
            $this->publisher->published = array_values(array_filter($this->publisher->published, fn (WorkWakeUp $wakeUp): bool => $wakeUp->notBefore->isFuture()));
            foreach ($due as $wakeUp) {
                $statuses[] = app(TenantContext::class)->run($wakeUp->tenantId, fn () => app(WorkProcessor::class)->process($wakeUp->signalId))->status;
            }
        }

        return $statuses;
    };

    // Rows of the daily aggregates of a tenant, without ids and timestamps, for comparing with a full recompute.
    $this->aggregates = fn (Team $tenant): array => ($this->inTenant)($tenant, fn () => [
        AggDaily::query()->orderBy('date')->orderBy('brand_id')->orderBy('engine_key')->orderBy('prompt_id')->get()
            ->map(fn (AggDaily $row): array => collect($row->getAttributes())->except(['id', 'created_at', 'updated_at'])->all())->all(),
        AggSourceDaily::query()->orderBy('date')->orderBy('domain')->get()
            ->map(fn (AggSourceDaily $row): array => collect($row->getAttributes())->except(['id', 'created_at', 'updated_at'])->all())->all(),
    ]);
});

test('a finished collection leads to extraction, the daily aggregates and the usage roll-up without any schedule', function () {
    $world = ($this->world)();
    $tenant = $world['tenant'];
    [$first, $second] = $world['runs'];

    // The first answer waits for the rest of its group: extraction is registered, but not due yet.
    ($this->collect)($tenant, $first);
    $signals = ($this->signals)($tenant);
    $group = 'extraction:'.WorkScope::runBatch($world['batch']->id);
    expect(array_keys($signals))->toEqualCanonicalizing([$group, 'usage_rollup:2026-11'])
        ->and($signals[$group]->available_at->toIso8601String())->toBe('2026-11-19T10:10:00+00:00');

    // The last answer finishes the group, and its extraction becomes due at once.
    ($this->collect)($tenant, $second);
    expect(($this->signals)($tenant)[$group]->available_at->toIso8601String())->toBe('2026-11-19T10:00:00+00:00');

    ($this->drain)();
    $job = ($this->inTenant)($tenant, fn () => ExtractionJob::query()->sole());
    expect($this->extraction->inner->batches)->toHaveCount(1)
        ->and($job->status)->toBe('submitted')
        ->and(($this->signals)($tenant))->toHaveKey('extraction:extraction_job:'.$job->id);

    // The batch is still running at the first status check: deferred, without a new submission.
    $this->travel(60)->seconds();
    ($this->drain)();
    expect($this->extraction->statusChecks)->toBe(1)
        ->and($this->extraction->inner->batches)->toHaveCount(1)
        ->and(($this->signals)($tenant)['extraction:extraction_job:'.$job->id]->deferred)->toBeTrue();

    // Once it has ended, the results are written and the day and the month follow, with no hourly job.
    $this->extraction->ended = true;
    $this->travel(60)->seconds();
    ($this->drain)();

    ($this->inTenant)($tenant, function () use ($job) {
        expect(Run::query()->pluck('status')->unique()->all())->toBe(['extracted'])
            ->and($job->refresh()->status)->toBe('completed')
            ->and(AggDaily::query()->where('date', '2026-11-19')->sum('responses'))->toEqual(2)
            ->and(UsageMonthly::query()->sole()->calls)->toBe(4);
    });
    $pending = array_filter(($this->signals)($tenant), fn (WorkSignal $signal): bool => $signal->isPending());
    expect($pending)->toBe([]);

    // The group's first, delayed wake-up is still out: when it comes, it finds nothing to do.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:10:00', 'UTC'));
    expect(($this->drain)())->toBe([WorkResult::IDLE])
        ->and($this->publisher->published)->toBe([])
        ->and($this->extraction->inner->batches)->toHaveCount(1);

    // The event-driven aggregates equal a full recompute on the same data.
    $chained = ($this->aggregates)($tenant);
    Artisan::call('ekko:aggregate', ['date' => '2026-11-19', '--sync' => true, '--tenant' => [$tenant->id]]);
    expect(($this->aggregates)($tenant))->toBe($chained);
});

test('with chaining off nothing is signalled and the scheduled path polls with PollExtractionBatch', function () {
    config(['ekko.work.chaining' => false]);
    Bus::fake([PollExtractionBatch::class]);
    $world = ($this->world)(engines: ['chain_engine']);
    $tenant = $world['tenant'];

    ($this->collect)($tenant, $world['runs'][0]);
    dispatch_sync(new SubmitExtractionBatch($tenant->id));

    expect(($this->signals)($tenant))->toBe([])
        ->and($this->publisher->published)->toBe([])
        ->and($this->extraction->inner->batches)->toHaveCount(1);
    Bus::assertDispatched(PollExtractionBatch::class);
});

test('the scheduled submit path also checks the batch through a work signal when chaining is on', function () {
    Bus::fake([PollExtractionBatch::class]);
    $world = ($this->world)(engines: ['chain_engine']);
    $tenant = $world['tenant'];
    ($this->collect)($tenant, $world['runs'][0]);

    // The fallback dispatcher and the chained handler share the submitter: one batch, one way to check it.
    dispatch_sync(new SubmitExtractionBatch($tenant->id));
    ($this->drain)();

    $job = ($this->inTenant)($tenant, fn () => ExtractionJob::query()->sole());
    expect($this->extraction->inner->batches)->toHaveCount(1)
        ->and(($this->signals)($tenant))->toHaveKey('extraction:extraction_job:'.$job->id);
    Bus::assertNotDispatched(PollExtractionBatch::class);
});

test('one slow engine does not hold back the rest of its group beyond the wait', function () {
    $world = ($this->world)(engines: ['chain_engine', 'chain_slow']);
    $tenant = $world['tenant'];
    [$fast, $slow] = $world['runs'];
    ($this->inTenant)($tenant, fn () => Run::query()->whereKey($slow->id)->update(['status' => 'running']));

    ($this->collect)($tenant, $fast);
    ($this->drain)();
    expect($this->extraction->inner->batches)->toBe([]);

    // After the group wait the collected answer goes on its own.
    $this->travel(600)->seconds();
    ($this->drain)();
    $fastResponse = ($this->inTenant)($tenant, fn () => Response::query()->where('run_id', $fast->id)->sole());
    expect($this->extraction->inner->batches)->toBe([['r'.$fastResponse->id]]);

    // The slow engine answers later: its group is finished now, so it goes at once.
    ($this->inTenant)($tenant, fn () => Run::query()->whereKey($slow->id)->update(['status' => 'queued']));
    ($this->collect)($tenant, $slow);
    ($this->drain)();
    $slowResponse = ($this->inTenant)($tenant, fn () => Response::query()->where('run_id', $slow->id)->sole());
    expect($this->extraction->inner->batches)->toBe([['r'.$fastResponse->id], ['r'.$slowResponse->id]]);
});

test('a failed or quota-stopped run finishes its group, so the collected answers go at once', function (string $how) {
    $world = ($this->world)(engines: ['chain_engine', 'chain_slow'], plan: $how === 'quota' ? Plan::factory()->create(['monthly_call_quota' => 1]) : null);
    $tenant = $world['tenant'];
    [$collected, $other] = $world['runs'];

    ($this->collect)($tenant, $collected);
    expect(($this->signals)($tenant)['extraction:'.WorkScope::runBatch($world['batch']->id)]->available_at->isFuture())->toBeTrue();

    if ($how === 'failed') {
        (new CollectEngineRun($tenant->id, $other->id))->failed(new RuntimeException('engine_collection_failed'));
    } else {
        // The quota of one call is used up by the first run: the second is skipped at admission.
        expect(($this->collect)($tenant, $other))->toBeNull();
    }

    expect(($this->inTenant)($tenant, fn () => $other->refresh()->status))->toBe($how === 'failed' ? 'failed' : 'skipped_quota')
        ->and(($this->signals)($tenant)['extraction:'.WorkScope::runBatch($world['batch']->id)]->available_at->isFuture())->toBeFalse();
    ($this->drain)();
    expect($this->extraction->inner->batches)->toHaveCount(1);
})->with(['failed', 'quota']);

test('a full model batch goes at once, even while its group is still collecting', function () {
    config(['ekko.anthropic.extraction.batch_size' => 2]);
    $world = ($this->world)(engines: ['chain_engine', 'chain_engine', 'chain_engine']);
    $tenant = $world['tenant'];

    ($this->collect)($tenant, $world['runs'][0]);
    expect(($this->signals)($tenant)['extraction:'.WorkScope::runBatch($world['batch']->id)]->available_at->isFuture())->toBeTrue();
    ($this->collect)($tenant, $world['runs'][1]);
    ($this->drain)();

    expect($this->extraction->inner->batches)->toHaveCount(1)
        ->and($this->extraction->inner->batches[0])->toHaveCount(2);
});

test('more answers than one batch are submitted batch by batch, each acknowledgement waking the next', function () {
    config(['ekko.anthropic.extraction.batch_size' => 1]);
    $world = ($this->world)(engines: ['chain_engine', 'chain_engine', 'chain_engine']);
    $tenant = $world['tenant'];
    foreach ($world['runs'] as $run) {
        ($this->collect)($tenant, $run);
    }

    ($this->drain)();

    expect($this->extraction->inner->batches)->toHaveCount(3)
        ->and(collect($this->extraction->inner->batches)->flatten()->unique()->count())->toBe(3)
        ->and(($this->signals)($tenant)['extraction:'.WorkScope::runBatch($world['batch']->id)]->isPending())->toBeFalse();
});

test('duplicate and late deliveries submit nothing twice', function () {
    $world = ($this->world)(engines: ['chain_engine']);
    $tenant = $world['tenant'];
    ($this->collect)($tenant, $world['runs'][0]);

    $wakeUps = $this->publisher->published;
    ($this->drain)();
    // The same wake-ups delivered again, and a recovery sweep on top: nothing left to claim.
    $this->publisher->published = $wakeUps;
    $statuses = ($this->drain)();
    Artisan::call('ekko:work:recover');

    expect($statuses)->each->toBe(WorkResult::IDLE)
        ->and($this->extraction->inner->batches)->toHaveCount(1);
});

test('signals that arrive while the aggregation runs are kept and processed afterwards', function () {
    $world = ($this->world)(engines: ['chain_engine', 'chain_engine']);
    $tenant = $world['tenant'];
    [$first, $second] = $world['runs'];
    ($this->collect)($tenant, $first);
    ($this->collect)($tenant, $second);
    $responses = ($this->inTenant)($tenant, fn () => Response::query()->orderBy('id')->get());

    // The first answer is extracted, and its day is claimed by a processor.
    ($this->inTenant)($tenant, fn () => app(ExtractionWriter::class)->markExtracted($responses[0]));
    $signal = ($this->signals)($tenant)['aggregation:2026-11-19'];
    $claim = ($this->inTenant)($tenant, fn () => app(WorkSignals::class)->claim($signal->id));

    // While it runs, the second answer is extracted from another session's point of view: a newer version.
    ($this->inTenant)($tenant, fn () => app(ExtractionWriter::class)->markExtracted($responses[1]));
    ($this->inTenant)($tenant, function () use ($claim) {
        app(AggregationWorkHandler::class)->handle($claim);
        app(WorkSignals::class)->acknowledge($claim);
    });
    expect(($this->signals)($tenant)['aggregation:2026-11-19']->isPending())->toBeTrue();

    ($this->drain)();
    ($this->inTenant)($tenant, fn () => expect((int) AggDaily::query()->where('date', '2026-11-19')->sum('responses'))->toBe(2));
    expect(($this->signals)($tenant)['aggregation:2026-11-19']->isPending())->toBeFalse();
});

test('two tenants are chained apart: neither the signals nor the processing cross tenants', function () {
    $a = ($this->world)('Europe/Oslo', ['chain_engine']);
    $b = ($this->world)('America/New_York', ['chain_engine']);

    // Collected 02:30 UTC: still the 18th in New York, the 19th in Oslo.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 02:30:00', 'UTC'));
    ($this->collect)($a['tenant'], $a['runs'][0]);
    ($this->collect)($b['tenant'], $b['runs'][0]);
    $this->extraction->ended = true;
    ($this->drain)();
    $this->travel(60)->seconds();
    ($this->drain)();

    expect($this->extraction->inner->batches)->toHaveCount(2)
        ->and(array_keys(array_filter(($this->signals)($a['tenant']), fn (WorkSignal $s): bool => $s->kind === WorkKind::Aggregation)))->toBe(['aggregation:2026-11-19'])
        ->and(array_keys(array_filter(($this->signals)($b['tenant']), fn (WorkSignal $s): bool => $s->kind === WorkKind::Aggregation)))->toBe(['aggregation:2026-11-18']);
    ($this->inTenant)($a['tenant'], fn () => expect(AggDaily::query()->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all())->toBe(['2026-11-19']));
    ($this->inTenant)($b['tenant'], fn () => expect(AggDaily::query()->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all())->toBe(['2026-11-18']));

    // A status check under one tenant for the other tenant's batch finds nothing: it is acknowledged untouched.
    $bJob = ($this->inTenant)($b['tenant'], fn () => ExtractionJob::query()->sole());
    $outcome = ($this->inTenant)($a['tenant'], fn () => app(ExtractionWorkHandler::class)->handle(new WorkClaim(
        $a['tenant']->id, 1, WorkKind::Extraction, WorkScope::extractionJob($bJob->id), 1, 'token', 1, CarbonImmutable::now()->addMinutes(6),
    )));
    expect($outcome->isDone())->toBeTrue()
        ->and(($this->inTenant)($b['tenant'], fn () => $bJob->refresh()->status))->toBe('completed');
});

test('an empty answer is extracted without a model call and its day aggregated', function () {
    $this->adapter->answer = '   ';
    $world = ($this->world)(engines: ['chain_engine']);
    $tenant = $world['tenant'];

    ($this->collect)($tenant, $world['runs'][0]);
    ($this->drain)();

    expect($this->extraction->inner->batches)->toBe([])
        ->and(($this->inTenant)($tenant, fn () => $world['runs'][0]->refresh()->status))->toBe('extracted')
        ->and(($this->signals)($tenant)['aggregation:2026-11-19']->isPending())->toBeFalse();
    ($this->inTenant)($tenant, fn () => expect((int) AggDaily::query()->where('date', '2026-11-19')->sum('responses'))->toBe(1));
});

test('status checks back off up to the cap, stop when the batch ends and give a batch up after the limit', function () {
    config(['ekko.work.extraction.status_max_seconds' => 600]);
    $world = ($this->world)(engines: ['chain_engine']);
    $tenant = $world['tenant'];
    ($this->collect)($tenant, $world['runs'][0]);
    ($this->drain)();
    $job = ($this->inTenant)($tenant, fn () => ExtractionJob::query()->sole());
    $scope = 'extraction:extraction_job:'.$job->id;

    // Each check is planned after the batch's age so far: 60, 60, 120, 240, 480, then the cap of 600 seconds.
    $gaps = [];
    $previous = CarbonImmutable::now();
    for ($check = 0; $check < 7; $check++) {
        $next = ($this->signals)($tenant)[$scope]->available_at;
        $gaps[] = (int) $previous->diffInSeconds($next);
        $this->travelTo($next);
        $previous = CarbonImmutable::now();
        ($this->drain)();
    }
    expect($gaps)->toBe([60, 60, 120, 240, 480, 600, 600])
        ->and($this->extraction->statusChecks)->toBe(7)
        ->and($this->extraction->inner->batches)->toHaveCount(1);

    // Past the limit the batch is given up (terminal fallback): deterministic results kept, llm_failed, extracted.
    $this->travelTo($job->submitted_at->addHours(25));
    ($this->drain)();
    ($this->inTenant)($tenant, function () use ($job, $world) {
        expect($job->refresh()->status)->toBe('failed')
            ->and(Response::query()->sole()->llm_failed)->toBeTrue()
            ->and($world['runs'][0]->refresh()->status)->toBe('extracted')
            ->and((int) AggDaily::query()->where('date', '2026-11-19')->sum('responses'))->toBe(1);
    });

    // A finished batch gets no more checks: nothing pending, nothing published.
    $checks = $this->extraction->statusChecks;
    $this->travel(1)->day();
    ($this->drain)();
    expect($this->extraction->statusChecks)->toBe($checks)
        ->and(($this->signals)($tenant)[$scope]->isPending())->toBeFalse()
        ->and($this->publisher->published)->toBe([]);
});

test('a rejected submission is retried with backoff, while an ambiguous one is never sent again', function () {
    Log::spy();
    $world = ($this->world)(engines: ['chain_engine', 'chain_engine']);
    $tenant = $world['tenant'];
    ($this->collect)($tenant, $world['runs'][0]);
    ($this->collect)($tenant, $world['runs'][1]);

    // Rejected before the provider created anything: the signal fails and is retried after its backoff.
    $this->extraction->failNext = new RuntimeException('anthropic_budget_unavailable');
    expect(($this->drain)())->toContain(WorkResult::FAILED)
        ->and($this->extraction->inner->batches)->toBe([]);
    $this->travelTo(($this->signals)($tenant)['extraction:'.WorkScope::runBatch($world['batch']->id)]->available_at);
    ($this->drain)();
    expect($this->extraction->inner->batches)->toHaveCount(1);

    // A broken connection: the provider may hold the batch, so the answers get the terminal fallback instead.
    $third = ($this->inTenant)($tenant, fn () => Run::factory()->for($world['batch'], 'runBatch')->for(Prompt::factory()->for($world['market']))->create(['engine_key' => 'chain_engine']));
    ($this->collect)($tenant, $third);
    $this->extraction->failNext = new APIConnectionException(new PsrRequest('POST', 'https://api.anthropic.com/v1/messages/batches'));
    ($this->drain)();
    expect($this->extraction->inner->batches)->toHaveCount(1)
        ->and(($this->inTenant)($tenant, fn () => [$third->refresh()->status, Response::query()->where('run_id', $third->id)->sole()->llm_failed]))->toBe(['extracted', true]);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $message === 'extraction.submit_ambiguous'
        && $context === ['tenant_id' => $tenant->id, 'responses' => 1, 'error_type' => APIConnectionException::class])->once();
});

test('a late result is aggregated on its own local day, across day, month and timezone boundaries', function (string $timezone, string $collectedAt, string $localDay) {
    $world = ($this->world)($timezone, ['chain_engine']);
    $tenant = $world['tenant'];
    $this->travelTo(CarbonImmutable::parse($collectedAt, 'UTC'));
    ($this->collect)($tenant, $world['runs'][0]);
    ($this->drain)();

    // The result comes in eleven days later: far outside the hourly lookback of today and three days back.
    $this->travel(11)->days();
    $this->extraction->ended = true;
    for ($round = 0; $round < 12; $round++) {
        ($this->drain)();
        $this->travel(15)->minutes();
    }

    expect(($this->signals)($tenant))->toHaveKey('aggregation:'.$localDay);
    $chained = ($this->aggregates)($tenant);
    ($this->inTenant)($tenant, fn () => expect(AggDaily::query()->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all())->toBe([$localDay]));

    Artisan::call('ekko:aggregate', ['date' => $localDay, '--sync' => true, '--tenant' => [$tenant->id]]);
    expect(($this->aggregates)($tenant))->toBe($chained);
})->with([
    'Oslo, the first of the month locally' => ['Europe/Oslo', '2026-11-30 23:30:00', '2026-12-01'],
    'Los Angeles, still the last of the month locally' => ['America/Los_Angeles', '2026-12-01 03:00:00', '2026-11-30'],
    'Auckland, a new year locally' => ['Pacific/Auckland', '2026-12-31 12:30:00', '2027-01-01'],
]);

test('usage is rolled up per month without counting usage or quota alerts twice', function () {
    $plan = Plan::factory()->create(['monthly_call_quota' => 2]);
    $world = ($this->world)(engines: ['chain_engine', 'chain_engine'], plan: $plan);
    $tenant = $world['tenant'];

    // An event just before the month ends, rolled up only after it: the previous month is refreshed.
    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:59:30', 'UTC'));
    ($this->collect)($tenant, $world['runs'][0]);
    ($this->collect)($tenant, $world['runs'][1]);
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:05:00', 'UTC'));
    ($this->drain)();

    $state = fn () => ($this->inTenant)($tenant, fn () => [
        UsageMonthly::query()->where('month', '2026-11-01')->get()->map(fn (UsageMonthly $row) => [substr((string) $row->month, 0, 10), $row->calls, $row->quota_alerted_threshold, $row->status])->all(),
        Alert::query()->count(),
    ]);
    [$months, $alerts] = $state();
    expect($months[0])->toBe(['2026-11-01', 2, 100, 'capped'])
        ->and($alerts)->toBe(2);

    // The hourly roll-up (which also starts December), the same signal again and a repeated delivery change nothing.
    $before = $state();
    Artisan::call('ekko:usage-rollup');
    ($this->inTenant)($tenant, fn () => app(WorkSignals::class)->signal(WorkKind::UsageRollup, '2026-11'));
    ($this->drain)();
    expect($state())->toBe($before);
});

test('usage of every producer registers its month, and a rollback registers nothing', function () {
    $world = ($this->world)(engines: ['chain_engine']);
    $tenant = $world['tenant'];

    expect(fn () => ($this->inTenant)($tenant, function () {
        UsageEvent::query()->create(['kind' => 'onboarding', 'occurred_at' => now(), 'calls' => 1, 'input_tokens' => 1, 'output_tokens' => 1, 'search_calls' => 0, 'cost_usd' => '0.01', 'billed_to' => 'platform']);

        throw new RuntimeException('rolled back');
    }))->toThrow(RuntimeException::class);
    expect(($this->signals)($tenant))->toBe([])
        ->and($this->publisher->published)->toBe([]);

    ($this->inTenant)($tenant, fn () => UsageEvent::query()->create(['kind' => 'pdf', 'occurred_at' => now(), 'calls' => 1, 'input_tokens' => 1, 'output_tokens' => 1, 'search_calls' => 0, 'cost_usd' => '0.01', 'billed_to' => 'platform']));
    expect(array_keys(($this->signals)($tenant)))->toBe(['usage_rollup:2026-11'])
        ->and($this->publisher->published)->toHaveCount(1);
});

test('the chain producers are no-ops while chaining is off', function () {
    config(['ekko.work.chaining' => false]);
    $world = ($this->world)(engines: ['chain_engine']);
    $tenant = $world['tenant'];

    ($this->inTenant)($tenant, function () use ($world) {
        $chain = app(WorkChain::class);
        $chain->collected($world['runs'][0]);
        $chain->ended($world['runs'][0]);
        $chain->usageRecorded(new UsageEvent(['occurred_at' => now()]));
    });

    expect(($this->signals)($tenant))->toBe([]);
});

test('a queued engine registers the extraction of its group when its task is fetched', function () {
    app()->forgetInstance(EngineRegistry::class);
    config(['engines.dataforseo.credentials' => 'fixture-login:fixture-password']);
    $engine = Engine::factory()->create(['key' => 'chatgpt_ui', 'provider' => 'dataforseo', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.02', 'price_per_1k_input_tokens_usd' => 0, 'price_per_1k_output_tokens_usd' => 0, 'price_per_search_usd' => 0]);
    $world = ($this->world)(engines: ['chatgpt_ui']);
    $tenant = $world['tenant'];
    $base = 'https://api.dataforseo.com/v3/ai_optimization/chat_gpt/llm_scraper';
    Http::preventStrayRequests()->fake([
        "$base/task_post" => Http::response(EngineFixtures::load('dataforseo', 'created')),
        "$base/tasks_ready" => Http::response(EngineFixtures::load('dataforseo', 'ready')),
        "$base/task_get/advanced/11150800-0000-0000-0000-123456789abc" => Http::response(EngineFixtures::load('chatgpt_ui', 'no-nb')),
    ]);
    $collect = fn () => app(TenantContext::class)->run($tenant->id, fn () => app(QueuedEngineCollector::class)->collect($world['runs'][0]->id));

    // Submitted: only the usage of the provider task is registered.
    $collect();
    expect(array_keys(($this->signals)($tenant)))->toBe(['usage_rollup:2026-11']);

    // Fetched: the run is collected and its group, now finished, is extracted at once.
    $collect();
    $signals = ($this->signals)($tenant);
    $group = 'extraction:'.WorkScope::runBatch($world['batch']->id);
    expect($signals)->toHaveKey($group)
        ->and($signals[$group]->available_at->isFuture())->toBeFalse();
    ($this->drain)();
    expect($this->extraction->inner->batches)->toHaveCount(1);
});

test('a batch status check has a scope of internal ids only', function () {
    expect(WorkKind::Extraction->accepts(WorkScope::extractionJob(42)))->toBeTrue()
        ->and(WorkScope::id('extraction_job:42', 'extraction_job'))->toBe(42)
        ->and(WorkScope::id('run_batch:42', 'extraction_job'))->toBeNull()
        ->and(WorkScope::type('extraction_job:42'))->toBe('extraction_job')
        ->and(WorkKind::Extraction->accepts('extraction_job:0'))->toBeFalse()
        ->and(WorkKind::Extraction->accepts('extraction_job:abc'))->toBeFalse()
        ->and(WorkKind::Aggregation->accepts('extraction_job:42'))->toBeFalse();
});

test('answers already in a submitted batch do not make the rest of the group look like a full batch', function () {
    config(['ekko.anthropic.extraction.batch_size' => 2]);
    $world = ($this->world)(engines: ['chain_engine', 'chain_engine', 'chain_engine', 'chain_engine']);
    $tenant = $world['tenant'];
    $group = 'extraction:'.WorkScope::runBatch($world['batch']->id);

    ($this->collect)($tenant, $world['runs'][0]);
    ($this->collect)($tenant, $world['runs'][1]);
    ($this->drain)();
    expect($this->extraction->inner->batches)->toHaveCount(1);

    // The first two are in flight; the third alone is no full batch and waits for the group.
    ($this->collect)($tenant, $world['runs'][2]);
    expect(($this->signals)($tenant)[$group]->available_at->isFuture())->toBeTrue();
    ($this->drain)();
    expect($this->extraction->inner->batches)->toHaveCount(1);

    // The last one finishes the group: both go together.
    ($this->collect)($tenant, $world['runs'][3]);
    ($this->drain)();
    expect($this->extraction->inner->batches)->toHaveCount(2)
        ->and($this->extraction->inner->batches[1])->toHaveCount(2);
});

test('the real client: a possibly accepted submission gets the fallback, a rejection is retried', function (int $status, bool $ambiguous) {
    config(['services.anthropic.key' => 'test-key-not-real']);
    $http = new FakeHttpClient(['POST /v1/messages/batches' => [$status, '{"type":"error","error":{"type":"api_error","message":"failure"}}']]);
    app()->instance(ExtractionClient::class, AnthropicExtractionClient::fromConfig($http));
    $world = ($this->world)(engines: ['chain_engine']);
    $tenant = $world['tenant'];
    ($this->collect)($tenant, $world['runs'][0]);

    $statuses = ($this->drain)();

    // Sent once, never by the SDK again; then either the terminal fallback or a backoff without it.
    $signal = ($this->signals)($tenant)['extraction:'.WorkScope::runBatch($world['batch']->id)];
    expect($http->requests)->toHaveCount(1)
        ->and(in_array(WorkResult::FAILED, $statuses, true))->toBe(! $ambiguous)
        ->and($signal->isPending())->toBe(! $ambiguous)
        ->and($signal->attempts)->toBe($ambiguous ? 0 : 1);
    ($this->inTenant)($tenant, function () use ($world, $ambiguous) {
        expect($world['runs'][0]->refresh()->status)->toBe($ambiguous ? 'extracted' : 'collected')
            ->and(Response::query()->sole()->llm_failed)->toBe($ambiguous);
    });
})->with([
    'server error' => [500, true],
    'request timeout' => [408, true],
    'bad request' => [400, false],
    'rate limited' => [429, false],
    'overloaded' => [529, false],
]);
