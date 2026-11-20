<?php

use App\Engines\RawResponseStore;
use App\Jobs\CollectEngineRun;
use App\Jobs\GenerateTenantActions;
use App\Jobs\SubmitExtractionBatch;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\TenantApiKey;
use App\Models\UsageEvent;
use App\Scheduling\CloudRunAccessToken;
use App\Scheduling\CollectionLocks;
use App\Scheduling\Dispatcher;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Engines\EngineFixtures;

function dispatchTenant(Engine $engine, int $prompts, int $quota = 100): Team
{
    $plan = Plan::factory()->create(['allowed_engines' => [$engine->key], 'monthly_call_quota' => $quota]);
    $tenant = Team::factory()->tenant()->withPlan($plan)->create();
    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () use ($engine, $prompts): void {
        $market = Market::factory()->create(['is_active' => true]);
        $market->brand->update(['status' => 'active']);
        MarketEngine::factory()->for($market)->for($engine)->create(['frequency' => 'daily']);
        Prompt::factory()->for($market)->count($prompts)->create(['is_active' => true, 'priority' => 'standard']);
        $market->prompts()->orderBy('id')->first()->update(['priority' => 'core']);
    }));

    return $tenant;
}

function dispatchEngine(): Engine
{
    $engine = Engine::factory()->create(['key' => 'chatgpt_api', 'provider' => 'openai', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.01']);

    return $engine;
}

test('an expired pitch plans no new runs while an active pitch still runs', function () {
    $now = CarbonImmutable::now('UTC');
    $engine = dispatchEngine();
    $expired = dispatchTenant($engine, 1);
    $active = dispatchTenant($engine, 1);
    $expired->forceFill(['kind' => 'pitch', 'pitch_expires_at' => $now])->save();
    $active->forceFill(['kind' => 'pitch', 'pitch_expires_at' => $now->addSecond()])->save();
    expect(app(Dispatcher::class)->dispatch($now))->toBe(1);
    app(TenantContext::class)->run($expired->id, fn () => app(TenantRls::class)->run([$expired->id], fn () => expect(Run::query()->count())->toBe(0)));
    app(TenantContext::class)->run($active->id, fn () => app(TenantRls::class)->run([$active->id], fn () => expect(Run::query()->count())->toBe(1)));
});

test('a pitch does not schedule an engine switched off for its market', function () {
    $now = CarbonImmutable::now('UTC');
    $engine = dispatchEngine();
    $pitch = dispatchTenant($engine, 1);
    $pitch->forceFill(['kind' => 'pitch', 'pitch_expires_at' => $now->addDays(7)])->save();
    app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], fn () => MarketEngine::query()->update(['frequency' => 'off'])));

    expect(app(Dispatcher::class)->dispatch($now))->toBe(0);
    app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], fn () => expect(Run::query()->count())->toBe(0)));
});

test('daily dispatch queues one template action refresh per pitch without adding customer jobs', function () {
    $now = CarbonImmutable::now('UTC');
    $engine = dispatchEngine();
    $pitch = dispatchTenant($engine, 1);
    $pitch->forceFill(['kind' => 'pitch', 'pitch_expires_at' => $now->addDays(7)])->save();
    dispatchTenant($engine, 1);
    $dispatcher = app(Dispatcher::class);
    $dispatcher->dispatch($now);
    $dispatcher->dispatch($now);
    $actions = fn () => DB::table('jobs')->pluck('payload')->map(fn ($payload) => unserialize(json_decode($payload, true)['data']['command']))
        ->filter(fn ($job) => $job instanceof GenerateTenantActions)->values();

    expect($actions())->toHaveCount(1)->and($actions()[0]->tenantId)->toBe($pitch->id)->and($actions()[0]->force)->toBeTrue();
    $dispatcher->dispatch($now->addDay());
    expect($actions())->toHaveCount(2);
    $dispatcher->dispatch($now->addDays(7));
    expect($actions())->toHaveCount(2);
});

test('a queued pitch run cannot start provider work after the pitch expires', function () {
    $now = CarbonImmutable::now('UTC');
    $this->travelTo($now);
    $engine = dispatchEngine();
    $pitch = dispatchTenant($engine, 1);
    $pitch->forceFill(['kind' => 'pitch', 'pitch_expires_at' => $now->addMinute()])->save();
    expect(app(Dispatcher::class)->dispatch($now))->toBe(1);
    $runId = app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], fn () => Run::query()->sole()->id));
    $this->travelTo($now->addMinute());
    Http::preventStrayRequests();
    app()->instance(RawResponseStore::class, Mockery::mock(RawResponseStore::class));
    app(TenantContext::class)->run($pitch->id, fn () => app()->call([new CollectEngineRun($pitch->id, $runId), 'handle']));
    app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], fn () => expect(Run::query()->sole()->error_code)->toBe('pitch_expired')));
    Http::assertNothingSent();
});

test('pitch expiry does not clear the reservation of an in-flight provider call', function () {
    $now = CarbonImmutable::now('UTC');
    $this->travelTo($now);
    $engine = dispatchEngine();
    $pitch = dispatchTenant($engine, 1);
    $pitch->forceFill(['kind' => 'pitch', 'pitch_expires_at' => $now->addMinute()])->save();
    app(Dispatcher::class)->dispatch($now);
    $run = app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], function () use ($now) {
        $run = Run::query()->sole();
        $run->forceFill(['status' => 'running', 'quota_reservation_token' => '11111111-1111-4111-8111-111111111111', 'quota_reserved_at' => $now])->save();

        return $run;
    }));
    $this->travelTo($now->addMinute());
    Http::preventStrayRequests();
    app()->instance(RawResponseStore::class, Mockery::mock(RawResponseStore::class));
    app(TenantContext::class)->run($pitch->id, fn () => app()->call([new CollectEngineRun($pitch->id, $run->id), 'handle']));
    app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], fn () => expect($run->fresh()->status)->toBe('running')
        ->and($run->fresh()->quota_reservation_token)->toBe('11111111-1111-4111-8111-111111111111')));
    Http::assertNothingSent();
});

test('pitch expiry preserves an older reservation while its provider lease is still held', function () {
    $now = CarbonImmutable::now('UTC');
    $this->travelTo($now);
    $engine = dispatchEngine();
    $pitch = dispatchTenant($engine, 1);
    $pitch->forceFill(['kind' => 'pitch', 'pitch_expires_at' => $now->subMinute()])->save();
    $run = app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], function () use ($engine, $now) {
        $market = Market::query()->sole();
        $prompt = Prompt::query()->sole();
        $batch = RunBatch::factory()->for($market)->create();

        return Run::factory()->for($batch, 'runBatch')->for($prompt)->for($engine)->create([
            'status' => 'running', 'quota_reservation_token' => '22222222-2222-4222-8222-222222222222', 'quota_reserved_at' => $now->subHour(),
        ]);
    }));
    DB::select('SELECT pg_advisory_lock(CAST(? AS bigint))', [-$run->id]);
    try {
        app()->instance(RawResponseStore::class, Mockery::mock(RawResponseStore::class));
        app(TenantContext::class)->run($pitch->id, fn () => app()->call([new CollectEngineRun($pitch->id, $run->id), 'handle']));
        app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], fn () => expect($run->fresh()->status)->toBe('running')
            ->and($run->fresh()->quota_reservation_token)->toBe('22222222-2222-4222-8222-222222222222')));
    } finally {
        DB::select('SELECT pg_advisory_unlock(CAST(? AS bigint))', [-$run->id]);
    }
});

test('a submitted pitch run created before expiry is still collected', function () {
    $now = CarbonImmutable::now('UTC');
    $this->travelTo($now);
    $engine = dispatchEngine();
    $pitch = dispatchTenant($engine, 1);
    $pitch->forceFill(['kind' => 'pitch', 'pitch_expires_at' => $now->addMinute()])->save();
    expect(app(Dispatcher::class)->dispatch($now))->toBe(1);
    $runId = app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], function () use ($now): int {
        $run = Run::query()->sole();
        $run->forceFill(['status' => 'submitted', 'created_at' => $now->subMinute(), 'provider_task_id' => 'synthetic-task'])->save();

        return $run->id;
    }));
    $this->travelTo($now->addMinute());
    // The rate limit is full, so the job returns after the expiry check and never calls the provider.
    $engine->update(['config' => ['calls_per_minute' => 1, 'concurrency' => 1]]);
    Cache::store('database')->put('engine:'.$engine->key.':rate', array_fill(0, 5, microtime(true)), 60);
    app()->instance(RawResponseStore::class, Mockery::mock(RawResponseStore::class));
    app(TenantContext::class)->run($pitch->id, fn () => app()->call([new CollectEngineRun($pitch->id, $runId), 'handle']));
    app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], function (): void {
        $run = Run::query()->sole();
        expect($run->status)->toBe('submitted')->and($run->error_code)->toBeNull();
    }));
});

test('dispatcher durably interleaves three tenants and a replay does not add jobs or runs', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15T01:00:00Z'));
    $engine = dispatchEngine();
    $tenants = [dispatchTenant($engine, 3), dispatchTenant($engine, 2), dispatchTenant($engine, 1)];
    $dispatcher = app(Dispatcher::class);
    expect($dispatcher->dispatch(CarbonImmutable::now()))->toBe(6)
        ->and($dispatcher->dispatch(CarbonImmutable::now()))->toBe(0);
    $jobs = DB::table('jobs')->orderBy('id')->pluck('payload')->map(fn ($payload) => unserialize(json_decode($payload, true)['data']['command']));
    expect($jobs->map(fn ($job) => $job->tenantId())->all())->toBe([
        $tenants[0]->id, $tenants[1]->id, $tenants[2]->id, $tenants[0]->id, $tenants[1]->id, $tenants[0]->id,
    ]);
    foreach ($tenants as $index => $tenant) {
        app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () use ($index) {
            expect(RunBatch::query()->count())->toBe(1)
                ->and(Run::query()->count())->toBe(3 - $index)
                ->and(Run::query()->whereNull('enqueued_at')->count())->toBe(0);
        }));
    }
});

test('dispatcher keeps only fitting core prompts when the schedule exceeds quota', function () {
    $engine = dispatchEngine();
    $tenant = dispatchTenant($engine, 3, 2);
    expect(app(Dispatcher::class)->dispatch(CarbonImmutable::now()))->toBe(1);
    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () {
        expect(Run::query()->where('status', 'skipped_quota')->count())->toBe(2)
            ->and(Run::query()->where('status', 'queued')->sole()->prompt->priority)->toBe('core');
    }));
});

test('poll-only dispatch does not plan a new day and only queues submitted runs that are due', function () {
    $engine = dispatchEngine();
    $tenant = dispatchTenant($engine, 2);
    app(Dispatcher::class)->dispatch(CarbonImmutable::now());
    DB::table('jobs')->delete();
    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () {
        $runs = Run::query()->orderBy('id')->get();
        $runs[0]->forceFill(['status' => 'submitted', 'enqueued_at' => null, 'next_poll_at' => now()->subMinute()])->save();
        $runs[1]->forceFill(['status' => 'submitted', 'enqueued_at' => null, 'next_poll_at' => now()->addHour()])->save();
    }));
    expect(app(Dispatcher::class)->dispatch(CarbonImmutable::now(), false))->toBe(1);
});

test('a poll while the extraction submit is still queued keeps planning the tenant on the database cache', function () {
    // T21-F1: the dispatcher queues SubmitExtractionBatch inside the tenant transaction. On the PostgreSQL database
    // cache (production), the held unique lock must not abort that transaction and fail the tenant's planning.
    config(['cache.default' => 'database']);
    app()->forgetInstance('cache.store');
    $engine = dispatchEngine();
    $tenant = dispatchTenant($engine, 2);
    $dispatcher = app(Dispatcher::class);
    $dispatcher->dispatch(CarbonImmutable::now());
    DB::table('jobs')->delete();
    $due = fn () => app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () {
        $runs = Run::query()->orderBy('id')->get();
        $runs[0]->forceFill(['status' => 'collected'])->save();
        $runs[1]->forceFill(['status' => 'submitted', 'enqueued_at' => null, 'next_poll_at' => now()->subMinute()])->save();
    }));

    $due();
    expect($dispatcher->dispatch(CarbonImmutable::now(), false))->toBe(1)->and($dispatcher->failures)->toBe(0);
    $due();
    expect($dispatcher->dispatch(CarbonImmutable::now(), false))->toBe(1)->and($dispatcher->failures)->toBe(0);

    $jobs = DB::table('jobs')->pluck('payload')->map(fn ($payload) => json_decode($payload, true)['displayName']);
    expect($jobs->filter(fn ($name) => $name === SubmitExtractionBatch::class)->count())->toBe(1);
});

test('queue insertion rolls back together with its run marker', function () {
    $engine = dispatchEngine();
    $tenant = dispatchTenant($engine, 1);
    Run::updating(function (Run $run): void {
        if ($run->isDirty('enqueued_at')) {
            throw new RuntimeException('fixture_marker_failure');
        }
    });
    $dispatcher = app(Dispatcher::class);
    expect($dispatcher->dispatch(CarbonImmutable::now()))->toBe(0)->and($dispatcher->failures)->toBe(1);
    expect(DB::table('jobs')->count())->toBe(0);
    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], fn () => expect(Run::query()->sole()->enqueued_at)->toBeNull()));
});

test('a durable database job executes collection with tenant context and records the result', function () {
    $engine = dispatchEngine();
    $tenant = dispatchTenant($engine, 1);
    Http::preventStrayRequests()->fake([
        'https://eu.api.openai.com/v1/responses' => Http::response(EngineFixtures::load('chatgpt_api', 'no-nb')),
    ]);
    $tenant->forceFill(['byok_enabled' => true])->save();
    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], fn () => TenantApiKey::query()->create([
        'provider' => 'openai', 'key' => 'synthetic-tenant-key', 'last_verified_at' => now(),
    ])));
    config(['engines.chatgpt_api.api_key' => 'unused-platform-key']);
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->expects('put')->once()->andReturn('gs://fixture/durable.json');
    app()->instance(RawResponseStore::class, $raw);
    expect(app(Dispatcher::class)->dispatch(CarbonImmutable::now()))->toBe(1);
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer synthetic-tenant-key'));
    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () {
        expect(Run::query()->sole()->status)->toBe('collected')
            ->and(UsageEvent::query()->count())->toBe(1)
            ->and(UsageEvent::query()->sole()->billed_to)->toBe('byok');
    }));
});

test('a tenant with a missing engine price does not prevent other tenants from being queued', function () {
    $missing = Engine::factory()->create(['key' => 'gemini_api', 'provider' => 'google', 'is_enabled' => true]);
    $invalid = dispatchTenant($missing, 1);
    $valid = dispatchTenant(dispatchEngine(), 1);
    $dispatcher = app(Dispatcher::class);
    expect($dispatcher->dispatch(CarbonImmutable::now()))->toBe(1)->and($dispatcher->failures)->toBe(1);
    $job = unserialize(json_decode(DB::table('jobs')->sole()->payload, true)['data']['command']);
    expect($job->tenantId())->toBe($valid->id);
    app(TenantContext::class)->run($invalid->id, fn () => app(TenantRls::class)->run([$invalid->id], fn () => expect(RunBatch::query()->count())->toBe(0)));
});

test('a failed queue marker does not prevent the next tenant from being enqueued', function () {
    $engine = dispatchEngine();
    $invalid = dispatchTenant($engine, 1);
    $valid = dispatchTenant($engine, 1);
    Run::updating(function (Run $run) use ($invalid): void {
        if ($run->tenant_id === $invalid->id && $run->isDirty('enqueued_at')) {
            throw new RuntimeException('fixture_marker_failure');
        }
    });
    $dispatcher = app(Dispatcher::class);
    expect($dispatcher->dispatch(CarbonImmutable::now()))->toBe(1)->and($dispatcher->failures)->toBe(1);
    $job = unserialize(json_decode(DB::table('jobs')->sole()->payload, true)['data']['command']);
    expect($job->tenantId())->toBe($valid->id);
});

test('a poll overlapping a dispatcher succeeds and still checks pending worker work', function () {
    config(['collection.cloud_run.enabled' => true, 'collection.cloud_run.project' => 'ekko-fixture', 'collection.cloud_run.region' => 'europe-west1']);
    $token = Mockery::mock(CloudRunAccessToken::class);
    $token->shouldReceive('get')->andReturn('fixture-token');
    app()->instance(CloudRunAccessToken::class, $token);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
    Http::preventStrayRequests()->fake([
        '*/executions*' => Http::response(['executions' => []]),
        '*:run' => Http::response(['name' => 'operation']),
    ]);
    $lock = CollectionLocks::make('ekko:dispatch', 3600);
    expect($lock->get())->toBeTrue();
    try {
        $this->artisan('ekko:dispatch-due', ['--poll' => true])->assertSuccessful();
        expect($lock->isOwnedByCurrentProcess())->toBeTrue();
        expect(DB::table('jobs')->count())->toBe(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/jobs/ekko-worker:run'));
    } finally {
        $lock->release();
    }
});

test('daily dispatch waits for an overlapping poll and then plans exactly once', function () {
    $engine = dispatchEngine();
    $tenant = dispatchTenant($engine, 1);
    $pollLock = CollectionLocks::make('ekko:dispatch', 3600);
    expect($pollLock->get())->toBeTrue();
    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($pollLock): void {
        $pollLock->release();
    });
    try {
        $dispatcher = app(Dispatcher::class);
        expect($dispatcher->dispatch(CarbonImmutable::now()))->toBe(1)
            ->and($dispatcher->dispatch(CarbonImmutable::now()))->toBe(0);
        Sleep::assertSleptTimes(1);
        app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () {
            expect(RunBatch::query()->count())->toBe(1)
                ->and(Run::query()->count())->toBe(1);
        }));
    } finally {
        Sleep::fake(false);
        $pollLock->release();
    }
});

test('daily dispatch fails visibly when the lock remains busy beyond its wait limit', function () {
    $lock = CollectionLocks::make('ekko:dispatch', 3600);
    expect($lock->get())->toBeTrue();
    Sleep::fake();
    Sleep::whenFakingSleep(fn () => $this->travel(301)->seconds());
    try {
        $this->artisan('ekko:dispatch-due')->assertFailed();
        expect($lock->isOwnedByCurrentProcess())->toBeTrue();
        expect(DB::table('jobs')->count())->toBe(0);
    } finally {
        Sleep::fake(false);
        $this->travelBack();
        $lock->release();
    }
});
