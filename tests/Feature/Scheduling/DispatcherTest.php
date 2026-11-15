<?php

use App\Engines\RawResponseStore;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Scheduling\Dispatcher;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

test('queue insertion rolls back together with its run marker', function () {
    $engine = dispatchEngine();
    $tenant = dispatchTenant($engine, 1);
    Run::updating(function (Run $run): void {
        if ($run->isDirty('enqueued_at')) {
            throw new RuntimeException('fixture_marker_failure');
        }
    });
    expect(fn () => app(Dispatcher::class)->dispatch(CarbonImmutable::now()))->toThrow(RuntimeException::class, 'fixture_marker_failure');
    expect(DB::table('jobs')->count())->toBe(0);
    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], fn () => expect(Run::query()->sole()->enqueued_at)->toBeNull()));
});

test('a durable database job executes collection with tenant context and records the result', function () {
    $engine = dispatchEngine();
    $tenant = dispatchTenant($engine, 1);
    Http::preventStrayRequests()->fake([
        'https://api.openai.com/v1/responses' => Http::response(EngineFixtures::load('chatgpt_api', 'no-nb')),
    ]);
    config(['engines.chatgpt_api.api_key' => 'synthetic-key']);
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->expects('put')->once()->andReturn('gs://fixture/durable.json');
    app()->instance(RawResponseStore::class, $raw);
    expect(app(Dispatcher::class)->dispatch(CarbonImmutable::now()))->toBe(1);
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(0);
    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () {
        expect(Run::query()->sole()->status)->toBe('collected')
            ->and(UsageEvent::query()->count())->toBe(1);
    }));
});
