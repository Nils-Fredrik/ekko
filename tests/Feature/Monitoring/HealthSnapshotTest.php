<?php

use App\Models\Engine;
use App\Models\ExtractionJob;
use App\Models\Run;
use App\Models\RunBatch;
use App\Monitoring\HealthSnapshot;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/Onboarding/helpers.php';

test('health aggregates the last day by engine through tenant scopes and excludes quota skips and closed tenants', function () {
    $this->freezeTime();
    [$a] = wizardTenant();
    [$b] = wizardTenant();
    [$closed] = wizardTenant();
    $closed->forceFill(['status' => 'closed'])->save();
    $engine = Engine::factory()->create();
    $boundary = Engine::factory()->create();
    inTenant($a, function () use ($engine, $boundary) {
        foreach ([$engine, $boundary] as $item) {
            Run::factory()->create(['engine_key' => $item->key, 'status' => 'failed', 'finished_at' => now()]);
            Run::factory()->count(4)->create(['engine_key' => $item->key, 'status' => 'extracted', 'finished_at' => now()->subHour()]);
        }
        Run::factory()->create(['engine_key' => $engine->key, 'status' => 'failed', 'finished_at' => now()->subDay()->subSecond()]);
        Run::factory()->create(['engine_key' => $engine->key, 'status' => 'failed', 'finished_at' => now()->addSecond()]);
        Run::factory()->create(['engine_key' => $engine->key, 'status' => 'skipped_quota', 'finished_at' => now()]);
    });
    inTenant($b, fn () => Run::factory()->create(['engine_key' => $engine->key, 'status' => 'failed', 'finished_at' => now()]));
    inTenant($closed, fn () => Run::factory()->create(['engine_key' => $engine->key, 'status' => 'failed', 'finished_at' => now()]));
    app(TenantContext::class)->forget();

    $snapshot = app(HealthSnapshot::class)->collect(CarbonImmutable::now('UTC'));
    expect($snapshot['engines'][$engine->key])->toBe(['total' => 6, 'failed' => 2, 'unhealthy' => true])
        ->and($snapshot['engines'][$boundary->key])->toBe(['total' => 5, 'failed' => 1, 'unhealthy' => false])
        ->and(app(TenantContext::class)->id())->toBeNull();
});

test('overdue signals use strict age boundaries and actual pending collection work', function () {
    $this->freezeTime();
    [$tenant] = wizardTenant();
    inTenant($tenant, function () {
        foreach ([-1, 0, 1] as $seconds) {
            $batch = RunBatch::factory()->create(['scheduled_for' => now()->subHours(6)->addSeconds($seconds)]);
            Run::factory()->create(['run_batch_id' => $batch->id, 'status' => 'submitted']);
            ExtractionJob::factory()->create(['submitted_at' => now()->subDay()->addSeconds($seconds)]);
        }
        $done = RunBatch::factory()->create(['scheduled_for' => now()->subDay(), 'status' => 'running']);
        Run::factory()->create(['run_batch_id' => $done->id, 'status' => 'collected', 'finished_at' => now()]);
        ExtractionJob::factory()->create(['submitted_at' => now()->subDays(2), 'status' => 'completed']);
        ExtractionJob::factory()->create(['submitted_at' => now()->subDays(2), 'status' => 'failed']);
    });
    $snapshot = app(HealthSnapshot::class)->collect(CarbonImmutable::now('UTC'));
    expect($snapshot['collection_overdue'])->toBe(1)->and($snapshot['extraction_overdue'])->toBe(1)->and($snapshot['work_stopped'])->toBe(0);
});

test('monitor emits bounded numeric signals and caches engine status without customer content', function () {
    [$tenant] = wizardTenant();
    $engine = Engine::factory()->create();
    inTenant($tenant, function () use ($engine) {
        Run::factory()->create(['engine_key' => $engine->key, 'status' => 'failed', 'finished_at' => now(), 'error_code' => 'synthetic-private-error']);
        ExtractionJob::factory()->create(['submitted_at' => now()->subHours(25), 'provider_batch_id' => 'synthetic-private-batch']);
    });
    Log::spy();
    $this->artisan('ekko:monitor-health')->assertSuccessful();
    Log::shouldHaveReceived('warning')->with('monitoring.issue', ['signal' => 'engine_error_rate', 'engine_key' => $engine->key, 'total' => 1, 'failed' => 1])->once();
    Log::shouldHaveReceived('warning')->with('monitoring.issue', ['signal' => 'extraction_overdue', 'count' => 1])->once();
    Log::shouldHaveReceived('warning')->twice();
    Log::shouldHaveReceived('info')->with('monitoring.completed')->once();
    expect(Cache::get('monitoring.engine_health')['engines'])->toBe([$engine->key => ['total' => 1, 'failed' => 1, 'unhealthy' => true]])
        ->and(json_encode(Cache::get('monitoring.engine_health')))->not->toContain('synthetic-private');
});
