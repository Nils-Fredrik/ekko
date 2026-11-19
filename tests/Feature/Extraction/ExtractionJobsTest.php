<?php

// Batch submission and result handling per tenant (docs/spec.md 8.2, ADR 0011).

use App\Detection\DeterministicDetector;
use App\Extraction\ExtractionClient;
use App\Jobs\PollExtractionBatch;
use App\Jobs\SubmitExtractionBatch;
use App\Jobs\TenantJob;
use App\Models\Brand;
use App\Models\Citation;
use App\Models\Competitor;
use App\Models\Engine;
use App\Models\ExtractionJob;
use App\Models\Market;
use App\Models\Mention;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\Extraction\FakeExtractionClient;

/**
 * One tenant with a brand (Fjordkaffe) and a competitor (Nordlys Rosteri) and a market in Norwegian.
 *
 * @return array{tenant: Team, brand: int, competitor: int, market: Market}
 */
function extractionTenant(): array
{
    $tenant = Team::factory()->tenant()->create();

    return app(TenantContext::class)->run($tenant->id, function () use ($tenant): array {
        $brand = Brand::factory()->create(['name' => 'Fjordkaffe']);
        $brand->aliases()->create(['alias' => 'Fjordkaffe', 'match_mode' => 'case_insensitive']);
        $brand->domains()->create(['domain' => 'fjordkaffe.no', 'include_subdomains' => true]);
        $competitor = Competitor::factory()->for($brand)->create();
        $competitor->aliases()->create(['alias' => 'Nordlys Rosteri', 'match_mode' => 'case_insensitive']);
        $competitor->domains()->create(['domain' => 'nordlys.no']);

        return [
            'tenant' => $tenant, 'brand' => $brand->id, 'competitor' => $competitor->id,
            'market' => Market::factory()->for($brand)->create(['language' => 'nb']),
        ];
    });
}

function collectedResponse(array $setup, string $text, string $status = 'collected'): Response
{
    return app(TenantContext::class)->run($setup['tenant']->id, function () use ($setup, $text, $status): Response {
        $prompt = Prompt::factory()->for($setup['market'])->create();
        $batch = RunBatch::factory()->for($setup['market'])->create();
        $run = Run::factory()->for($batch, 'runBatch')->for($prompt)->for(Engine::factory()->create())->create(['status' => $status]);

        return Response::factory()->create(['run_id' => $run->id, 'answer_text' => $text]);
    });
}

/** @param  array<string, mixed>  $overrides */
function entry(array $mentions, array $citations = [], string $type = 'list', int $in = 120, int $out = 30): array
{
    return ['batch' => ['text' => ['mentions' => $mentions, 'citations' => $citations, 'answer_type' => $type], 'input_tokens' => $in, 'output_tokens' => $out]];
}

function reported(string $surface, string $type, ?int $id, bool $recommended, float $sentiment = 0.5, ?int $position = null): array
{
    return [
        'surface_name' => $surface, 'entity' => ['type' => $type, 'id' => $id], 'list_position' => $position,
        'is_recommended' => $recommended, 'sentiment' => $sentiment, 'evidence' => 'x',
    ];
}

/** Runs the callback as a tenant job would see it: in the tenant context and under row-level security. */
function asTenant(Team $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], $callback));
}

it('does not extract a run created after the pitch expired', function () {
    $a = extractionTenant();
    $a['tenant']->forceFill(['kind' => 'pitch', 'pitch_expires_at' => now()])->save();
    $response = collectedResponse($a, 'Et innsamlet svar.');
    $client = new FakeExtractionClient(fn (string $id): array => entry([]));
    app()->instance(ExtractionClient::class, $client);

    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));

    expect($client->batches)->toBeEmpty()
        ->and($response->run()->withoutGlobalScopes()->first()->status)->toBe('collected');
});

it('extracts answers from runs created before the pitch expired', function () {
    Bus::fake([PollExtractionBatch::class]);
    $a = extractionTenant();
    $before = collectedResponse($a, 'Innsamlet før utløp.');
    app(TenantContext::class)->run($a['tenant']->id, fn () => Run::query()->whereKey($before->run_id)->update(['created_at' => now()->subDay()]));
    collectedResponse($a, 'Opprettet etter utløp.');
    $a['tenant']->forceFill(['kind' => 'pitch', 'pitch_expires_at' => now()->subMinute()])->save();
    $client = new FakeExtractionClient(fn (string $id): array => entry([]));
    app()->instance(ExtractionClient::class, $client);

    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));

    expect($client->batches)->toBe([["r{$before->id}"]]);
});

it('submits one batch per tenant for collected answers and stores the batch as an extraction job', function () {
    Bus::fake([PollExtractionBatch::class]);
    $a = extractionTenant();
    $b = extractionTenant();
    $first = collectedResponse($a, 'Jeg anbefaler Fjordkaffe.');
    $second = collectedResponse($a, 'Nordlys Rosteri er kjent.');
    $other = collectedResponse($b, 'Fjordkaffe hos en annen tenant.');
    collectedResponse($a, 'Allerede ferdig.', 'extracted');

    $client = new FakeExtractionClient(fn (string $id): array => entry([]));
    app()->instance(ExtractionClient::class, $client);

    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));

    expect($client->batches)->toBe([["r{$first->id}", "r{$second->id}"]])
        ->and(collect($client->requests)->pluck('user')->implode("\n"))->not->toContain('annen tenant');

    $jobs = ExtractionJob::query()->withoutGlobalScopes()->get();
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->tenant_id)->toBe($a['tenant']->id)
        ->and($jobs[0]->status)->toBe('submitted')
        ->and($jobs[0]->response_ids)->toBe([$first->id, $second->id])
        ->and($jobs[0]->provider_batch_id)->toBe('batch-1');
    Bus::assertDispatched(PollExtractionBatch::class, fn (PollExtractionBatch $job): bool => $job->tenantId === $a['tenant']->id && $job->extractionJobId === $jobs[0]->id);

    // The other tenant's answer is untouched, and a second submit does not send the same answers again.
    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));
    expect($client->batches)->toHaveCount(1)
        ->and($other->run()->withoutGlobalScopes()->first()->status)->toBe('collected');
});

it('sends the market language, the catalog and the deterministic result with each answer', function () {
    $a = extractionTenant();
    $response = collectedResponse($a, "Topplisten:\n1. Nordlys Rosteri\n2. Fjordkaffe");
    $client = new FakeExtractionClient(fn (string $id): array => entry([]));
    app()->instance(ExtractionClient::class, $client);

    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));

    $user = $client->requests[0]->user;
    expect($client->requests[0]->customId)->toBe("r{$response->id}")
        ->and($user)->toContain('market_language: nb')
        ->and($user)->toContain('"aliases":["Fjordkaffe"]')
        ->and($user)->toContain('"surface_name":"Nordlys Rosteri","list_position":1')
        ->and($user)->toContain('"surface_name":"Fjordkaffe","list_position":2');
});

it('writes the combined result of a batch: enriched mentions, unknown companies, citations, usage and run status', function () {
    $a = extractionTenant();
    $text = "Topplisten:\n1. Nordlys Rosteri\n2. Fjordkaffe\nKaffebønna AS er nytt, se https://www.dagbladet.no/test?utm_source=x og https://fjordkaffe.no/kjop.";
    $listed = collectedResponse($a, $text);
    $badThenGood = collectedResponse($a, 'Jeg anbefaler Fjordkaffe.');
    $failing = collectedResponse($a, 'Nordlys Rosteri nevnes her.');
    $empty = collectedResponse($a, '   ');

    $client = new FakeExtractionClient(function (string $id) use ($a, $listed, $badThenGood, $failing): array {
        return match ($id) {
            "r{$listed->id}" => entry([
                reported('Nordlys Rosteri', 'competitor', $a['competitor'], false, 0.1, 1),
                reported('Fjordkaffe', 'brand', $a['brand'], true, 0.9, 7),
                reported('Kaffebønna AS', 'unknown', null, false, -0.3),
            ], [
                ['url' => 'https://www.dagbladet.no/test?utm_source=x', 'source_type' => 'news'],
                ['url' => 'https://fjordkaffe.no/kjop', 'source_type' => 'marketplace'],
                ['url' => 'ftp://nope.example/a', 'source_type' => 'other'],
            ]),
            "r{$badThenGood->id}" => ['batch' => ['text' => 'not json', 'input_tokens' => 50, 'output_tokens' => 5]]
                + ['retry' => entry([reported('Fjordkaffe', 'brand', $a['brand'], true, 0.8)], [], 'single_recommendation', 60, 20)['batch']],
            "r{$failing->id}" => ['batch' => ['error' => 'errored'], 'retry' => ['text' => 'still not json', 'input_tokens' => 40, 'output_tokens' => 4]],
        };
    });
    app()->instance(ExtractionClient::class, $client);

    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));
    $job = ExtractionJob::query()->withoutGlobalScopes()->sole();
    dispatch_sync(new PollExtractionBatch($a['tenant']->id, $job->id));

    expect($client->batches[0])->not->toContain("r{$empty->id}")
        ->and($client->retries)->toBe(["r{$badThenGood->id}", "r{$failing->id}"]);

    app(TenantContext::class)->run($a['tenant']->id, function () use ($a, $listed, $badThenGood, $failing, $empty, $job) {
        $mentions = Mention::query()->where('response_id', $listed->id)->orderBy('first_char_offset')->get();
        expect($mentions)->toHaveCount(3)
            ->and($mentions[0]->entity_id)->toBe($a['competitor'])
            ->and($mentions[0]->detection)->toBe('deterministic')
            ->and($mentions[0]->is_recommended)->toBeFalse()
            ->and($mentions[0]->list_position)->toBe(1)
            ->and($mentions[1]->entity_id)->toBe($a['brand'])
            ->and($mentions[1]->is_recommended)->toBeTrue()
            ->and($mentions[1]->sentiment)->toBe('0.900')
            ->and($mentions[1]->list_position)->toBe(2)           // the deterministic position, not the model's 7
            ->and($mentions[2]->entity_type)->toBe('unknown')
            ->and($mentions[2]->entity_id)->toBeNull()
            ->and($mentions[2]->detection)->toBe('llm')
            ->and($mentions[2]->surface_name)->toBe('Kaffebønna AS')
            ->and($mentions[2]->sentiment)->toBe('-0.300');

        $citations = Citation::query()->where('response_id', $listed->id)->orderBy('rank_in_answer')->get();
        expect($citations)->toHaveCount(2)
            ->and($citations[0]->url_normalized)->toBe('https://dagbladet.no/test')
            ->and($citations[0]->source_type)->toBe('news')
            ->and($citations[0]->ownership)->toBe('third_party')
            ->and($citations[0]->from_annotation)->toBeFalse()
            ->and($citations[1]->ownership)->toBe('own')
            ->and($citations[1]->source_type)->toBe('marketplace');

        expect($listed->refresh()->answer_type)->toBe('list')
            ->and($listed->llm_failed)->toBeFalse()
            ->and($listed->run->status)->toBe('extracted')
            ->and($badThenGood->refresh()->answer_type)->toBe('single_recommendation')
            ->and(Mention::query()->where('response_id', $badThenGood->id)->sole()->is_recommended)->toBeTrue();

        // Failed twice: the deterministic mention stays, the response is flagged, the run is still extracted.
        $failed = $failing->refresh();
        expect($failed->llm_failed)->toBeTrue()
            ->and($failed->answer_type)->toBeNull()
            ->and($failed->run->status)->toBe('extracted')
            ->and(Mention::query()->where('response_id', $failing->id)->sole()->detection)->toBe('deterministic');

        // An empty answer is not sent to the model and needs no flag.
        expect($empty->refresh()->llm_failed)->toBeFalse()
            ->and($empty->run->status)->toBe('extracted')
            ->and($job->refresh()->status)->toBe('completed')
            ->and($job->completed_at)->not->toBeNull();

        // Usage: one event per billed call. Batch calls get the batch discount, the retry does not.
        $usage = UsageEvent::query()->where('kind', 'extraction')->orderBy('id')->get();
        expect($usage)->toHaveCount(4)
            ->and($usage->pluck('run_id')->unique()->count())->toBe(3)
            ->and($usage->every(fn (UsageEvent $event): bool => $event->engine_key === null && $event->billed_to === 'platform' && $event->calls === 1))->toBeTrue();
        $byRun = $usage->groupBy('run_id');
        // (0.10 * 120 + 0.50 * 30) / 1e6 * 0.5
        expect($byRun[$listed->run_id]->sole()->cost_usd)->toBe('0.00001350');
        // batch 50 in / 5 out discounted, then retry 60 in / 20 out at full price
        expect($byRun[$badThenGood->run_id]->pluck('cost_usd')->all())->toBe(['0.00000375', '0.00001600']);
    });
});

it('is safe to run again and does not log usage twice', function () {
    $a = extractionTenant();
    $response = collectedResponse($a, 'Fjordkaffe er bra.');
    app()->instance(ExtractionClient::class, new FakeExtractionClient(fn (string $id): array => entry([reported('Fjordkaffe', 'brand', $a['brand'], true)])));

    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));
    $job = ExtractionJob::query()->withoutGlobalScopes()->sole();
    dispatch_sync(new PollExtractionBatch($a['tenant']->id, $job->id));
    dispatch_sync(new PollExtractionBatch($a['tenant']->id, $job->id));

    app(TenantContext::class)->run($a['tenant']->id, function () use ($response) {
        expect(UsageEvent::query()->where('kind', 'extraction')->count())->toBe(1)
            ->and(Mention::query()->where('response_id', $response->id)->count())->toBe(1);
    });
});

/** Runs a poll job by hand, in its tenant, while Bus::fake() holds back the automatic one. */
function runPoll(Team $tenant, int $extractionJobId): void
{
    $poll = new PollExtractionBatch($tenant->id, $extractionJobId);
    asTenant($tenant, fn () => app()->call([$poll, 'handle']));
}

it('keeps recomputing after a re-detect: model enrichment never relies on earlier deterministic rows', function () {
    $a = extractionTenant();
    Bus::fake([PollExtractionBatch::class]);
    $response = collectedResponse($a, 'Fjordkaffe er bra.');
    app()->instance(ExtractionClient::class, new FakeExtractionClient(fn (string $id): array => entry([reported('Fjordkaffe', 'brand', $a['brand'], true, 0.4)])));

    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));
    // Something re-ran deterministic detection after submission: its rows are new and not yet enriched.
    app(TenantContext::class)->run($a['tenant']->id, fn () => app(DeterministicDetector::class)->detect($response));
    $job = ExtractionJob::query()->withoutGlobalScopes()->sole();
    runPoll($a['tenant'], $job->id);

    app(TenantContext::class)->run($a['tenant']->id, function () use ($response) {
        $mention = Mention::query()->where('response_id', $response->id)->sole();
        expect($mention->is_recommended)->toBeTrue()->and($mention->sentiment)->toBe('0.400');
    });
});

it('lets annotated citations win and only adds source_type to them', function () {
    $a = extractionTenant();
    $response = collectedResponse($a, 'Fjordkaffe, se https://www.dagbladet.no/test og https://example.org/other.');
    app(TenantContext::class)->run($a['tenant']->id, function () use ($response) {
        Citation::query()->create([
            'response_id' => $response->id, 'url' => 'https://www.dagbladet.no/test?utm_source=chatgpt', 'url_normalized' => 'https://dagbladet.no/test',
            'domain' => 'dagbladet.no', 'ownership' => 'third_party', 'from_annotation' => true, 'rank_in_answer' => 1,
        ]);
        // A source the engine gave that the answer text never writes out: the model only sees it in sources.
        Citation::query()->create([
            'response_id' => $response->id, 'url' => 'https://www.trustpilot.com/review/fjordkaffe.no', 'url_normalized' => 'https://trustpilot.com/review/fjordkaffe.no',
            'domain' => 'trustpilot.com', 'ownership' => 'third_party', 'from_annotation' => true, 'rank_in_answer' => 2,
        ]);
    });
    $client = new FakeExtractionClient(fn (string $id): array => entry(
        [reported('Fjordkaffe', 'brand', $a['brand'], false)],
        [
            ['url' => 'https://www.dagbladet.no/test', 'source_type' => 'news'],
            ['url' => 'https://example.org/other', 'source_type' => 'forum'],
            ['url' => 'https://www.trustpilot.com/review/fjordkaffe.no', 'source_type' => 'review'],
        ],
    ));
    app()->instance(ExtractionClient::class, $client);

    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));
    dispatch_sync(new PollExtractionBatch($a['tenant']->id, ExtractionJob::query()->withoutGlobalScopes()->sole()->id));

    expect($client->requests[0]->user)->toContain('sources:'."\n".'["https://www.dagbladet.no/test?utm_source=chatgpt","https://www.trustpilot.com/review/fjordkaffe.no"]');
    app(TenantContext::class)->run($a['tenant']->id, function () use ($response) {
        $citations = Citation::query()->where('response_id', $response->id)->orderBy('rank_in_answer')->get();
        expect($citations)->toHaveCount(2)
            ->and($citations->pluck('from_annotation')->all())->toBe([true, true])
            ->and($citations->pluck('source_type')->all())->toBe(['news', 'review']);
    });
});

it('drops model citations and unknown companies the answer does not contain', function () {
    // T39-F10: without annotations, a model citation needs its URL written out or its domain named in the answer.
    $a = extractionTenant();
    $response = collectedResponse($a, 'Fjordkaffe og Kaffebønna AS, ifølge VG.no og https://www.dagbladet.no/test.');
    $client = new FakeExtractionClient(fn (string $id): array => entry(
        [
            reported('Fjordkaffe', 'brand', $a['brand'], false),
            reported('Kaffebønna AS', 'unknown', null, false),
            reported('Oppdiktet Kaffe AS', 'unknown', null, true),
        ],
        [
            ['url' => 'https://dagbladet.no/test/', 'source_type' => 'news'],
            ['url' => 'https://www.vg.no/nyheter/kaffe', 'source_type' => 'news'],
            ['url' => 'https://made-up.example/kaffe', 'source_type' => 'review'],
            ['url' => 'https://g.no/kaffe', 'source_type' => 'other'],
        ],
    ));
    app()->instance(ExtractionClient::class, $client);

    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));
    dispatch_sync(new PollExtractionBatch($a['tenant']->id, ExtractionJob::query()->withoutGlobalScopes()->sole()->id));

    app(TenantContext::class)->run($a['tenant']->id, function () use ($response) {
        expect(Citation::query()->where('response_id', $response->id)->orderBy('rank_in_answer')->pluck('url_normalized')->all())
            ->toBe(['https://dagbladet.no/test', 'https://vg.no/nyheter/kaffe'])
            ->and(Mention::query()->where('response_id', $response->id)->where('detection', 'llm')->pluck('surface_name')->all())
            ->toBe(['Kaffebønna AS']);
    });
});

it('waits for a batch that has not ended instead of writing anything', function () {
    $a = extractionTenant();
    $response = collectedResponse($a, 'Fjordkaffe er bra.');
    $client = new FakeExtractionClient(fn (string $id): array => entry([reported('Fjordkaffe', 'brand', $a['brand'], true)]), ended: false);
    app()->instance(ExtractionClient::class, $client);

    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));
    $extractionJob = ExtractionJob::query()->withoutGlobalScopes()->sole();

    $poll = (new PollExtractionBatch($a['tenant']->id, $extractionJob->id))->withFakeQueueInteractions();
    asTenant($a['tenant'], fn () => app()->call([$poll, 'handle']));

    $poll->assertReleased(config('ekko.anthropic.extraction.poll_delay_seconds'));
    expect($extractionJob->refresh()->status)->toBe('submitted')
        ->and($response->run()->withoutGlobalScopes()->first()->status)->toBe('collected');
});

it('never lets one tenant poll or write another tenants batch', function () {
    Bus::fake([PollExtractionBatch::class]);
    $a = extractionTenant();
    $b = extractionTenant();
    collectedResponse($a, 'Fjordkaffe er bra.');
    app()->instance(ExtractionClient::class, new FakeExtractionClient(fn (string $id): array => entry([])));
    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));
    $jobOfA = ExtractionJob::query()->withoutGlobalScopes()->sole();

    expect(fn () => runPoll($b['tenant'], $jobOfA->id))->toThrow(ModelNotFoundException::class);
    expect($jobOfA->refresh()->status)->toBe('submitted');
});

/** Runs a submit job by hand, in its tenant, while Bus::fake() holds back the jobs it queues. */
function runSubmit(Team $tenant): void
{
    $submit = new SubmitExtractionBatch($tenant->id);
    asTenant($tenant, fn () => app()->call([$submit, 'handle']));
}

it('submits at most one batch per run and queues itself again while answers wait', function () {
    Bus::fake();
    config(['ekko.anthropic.extraction.batch_size' => 2]);
    $a = extractionTenant();
    $ids = array_map(fn (int $i): int => collectedResponse($a, "Fjordkaffe nummer {$i}.")->id, range(1, 5));
    $client = new FakeExtractionClient(fn (string $id): array => entry([]));
    app()->instance(ExtractionClient::class, $client);

    runSubmit($a['tenant']);

    expect($client->batches)->toBe([["r{$ids[0]}", "r{$ids[1]}"]]);
    Bus::assertDispatched(SubmitExtractionBatch::class, fn (SubmitExtractionBatch $job): bool => $job->tenantId === $a['tenant']->id);
    Bus::assertDispatchedTimes(PollExtractionBatch::class, 1);

    // The next runs skip the answers that wait for their results.
    runSubmit($a['tenant']);
    runSubmit($a['tenant']);
    expect(array_map('count', $client->batches))->toBe([2, 2, 1]);
    // The queued successor holds the unique lock, so the later runs queue no second copy.
    Bus::assertDispatchedTimes(SubmitExtractionBatch::class, 1);

    runSubmit($a['tenant']);
    expect($client->batches)->toHaveCount(3);
});

it('does not send answers of only whitespace, tabs or line breaks to the model', function () {
    Bus::fake();
    $a = extractionTenant();
    $blank = [collectedResponse($a, "\n\n"), collectedResponse($a, "\t \r\n"), collectedResponse($a, '   ')];
    $real = collectedResponse($a, 'Fjordkaffe er bra.');
    $client = new FakeExtractionClient(fn (string $id): array => entry([]));
    app()->instance(ExtractionClient::class, $client);

    runSubmit($a['tenant']);

    expect($client->batches)->toBe([["r{$real->id}"]]);
    foreach ($blank as $response) {
        expect($response->run()->withoutGlobalScopes()->first()->status)->toBe('extracted');
    }
});

it('takes a lock per tenant for the run, held until the transaction ends', function () {
    Bus::fake();
    $a = extractionTenant();
    app()->instance(ExtractionClient::class, new FakeExtractionClient(fn (string $id): array => entry([])));

    runSubmit($a['tenant']);

    // The lock belongs to the (test) transaction, so it is still visible here: key pair (namespace, tenant id).
    $held = DB::table('pg_locks')->whereRaw('pid = pg_backend_pid()')->where('locktype', 'advisory')->where('classid', 20261115)->where('objid', $a['tenant']->id)->count();
    expect($held)->toBe(1);
});

it('does not turn a failed batch into a completed one when the job is run again', function () {
    Bus::fake([PollExtractionBatch::class]);
    $a = extractionTenant();
    $response = collectedResponse($a, 'Fjordkaffe er bra.');
    app()->instance(ExtractionClient::class, new FakeExtractionClient(fn (string $id): array => entry([reported('Fjordkaffe', 'brand', $a['brand'], true)])));
    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));
    $job = ExtractionJob::query()->withoutGlobalScopes()->sole();
    (new PollExtractionBatch($a['tenant']->id, $job->id))->failed(new RuntimeException);

    runPoll($a['tenant'], $job->id);

    expect($job->refresh()->status)->toBe('failed');
    app(TenantContext::class)->run($a['tenant']->id, fn () => expect($response->refresh()->llm_failed)->toBeTrue()
        ->and(UsageEvent::query()->count())->toBe(0));
});

it('does not let answers that wait for results crowd out new ones', function () {
    Bus::fake();
    config(['ekko.anthropic.extraction.batch_size' => 2]);
    $a = extractionTenant();
    $waiting = [collectedResponse($a, 'Fjordkaffe en.')->id, collectedResponse($a, 'Fjordkaffe to.')->id];
    $fresh = collectedResponse($a, 'Fjordkaffe tre.');
    app(TenantContext::class)->run($a['tenant']->id, fn () => ExtractionJob::factory()->create(['response_ids' => $waiting, 'status' => 'submitted']));
    $client = new FakeExtractionClient(fn (string $id): array => entry([]));
    app()->instance(ExtractionClient::class, $client);

    runSubmit($a['tenant']);

    expect($client->batches)->toBe([["r{$fresh->id}"]]);
});

it('keeps the deterministic results and flags the answers when the poll job gives up', function () {
    Bus::fake([PollExtractionBatch::class]);
    $a = extractionTenant();
    $first = collectedResponse($a, 'Fjordkaffe er bra.');
    $second = collectedResponse($a, 'Nordlys Rosteri er kjent.');
    app()->instance(ExtractionClient::class, new FakeExtractionClient(fn (string $id): array => entry([]), ended: false));
    dispatch_sync(new SubmitExtractionBatch($a['tenant']->id));
    $job = ExtractionJob::query()->withoutGlobalScopes()->sole();

    (new PollExtractionBatch($a['tenant']->id, $job->id))->failed(new RuntimeException('gave up: contains SECRET-ANSWER'));

    app(TenantContext::class)->run($a['tenant']->id, function () use ($first, $second, $job) {
        expect($job->refresh()->status)->toBe('failed')
            ->and($first->refresh()->llm_failed)->toBeTrue()
            ->and($first->run->status)->toBe('extracted')
            ->and($second->refresh()->llm_failed)->toBeTrue()
            ->and(Mention::query()->where('response_id', $first->id)->sole()->detection)->toBe('deterministic');
    });

    // A job that already finished is left alone.
    $other = extractionTenant();
    $done = collectedResponse($other, 'Fjordkaffe.');
    $finished = app(TenantContext::class)->run($other['tenant']->id, fn () => ExtractionJob::factory()->create(['response_ids' => [$done->id], 'status' => 'completed']));
    (new PollExtractionBatch($other['tenant']->id, $finished->id))->failed(new RuntimeException);
    expect($done->refresh()->llm_failed)->toBeFalse();
});

it('backs off between retries of an exception and has a lock that expires', function () {
    expect((new PollExtractionBatch(1, 1))->backoff)->toBe([60, 300, 900])
        ->and((new SubmitExtractionBatch(1))->uniqueFor)->toBe(3600);
});

it('runs as tenant jobs, and the submit job is unique per tenant', function () {
    expect(is_subclass_of(SubmitExtractionBatch::class, TenantJob::class))->toBeTrue()
        ->and(is_subclass_of(PollExtractionBatch::class, TenantJob::class))->toBeTrue()
        ->and((new SubmitExtractionBatch(7))->uniqueId())->toBe('extraction-submit:7')
        ->and(new SubmitExtractionBatch(7))->toBeInstanceOf(ShouldBeUnique::class);
});
