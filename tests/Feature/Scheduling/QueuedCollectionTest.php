<?php

use App\Engines\EngineCallFailed;
use App\Engines\QueuedEngineCollector;
use App\Engines\RawResponseStore;
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
use Illuminate\Support\Facades\Http;
use Tests\Support\Engines\EngineFixtures;

function queuedFixture(int $quota = 100): array
{
    app()->instance(RawResponseStore::class, Mockery::mock(RawResponseStore::class));
    config(['engines.dataforseo.credentials' => 'fixture-login:fixture-password']);
    $tenant = Team::factory()->tenant()->withPlan(Plan::factory()->create(['monthly_call_quota' => $quota]))->create();
    $engine = Engine::factory()->create(['key' => 'chatgpt_ui', 'provider' => 'dataforseo', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay(), 'price_per_call_usd' => '0.02', 'price_per_1k_input_tokens_usd' => 0, 'price_per_1k_output_tokens_usd' => 0, 'price_per_search_usd' => 0]);
    $run = app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () use ($engine) {
        $market = Market::factory()->create(['country' => 'NO', 'language' => 'nb', 'location_label' => null]);
        $prompt = Prompt::factory()->for($market)->create();
        $batch = RunBatch::factory()->for($market)->create();

        return Run::factory()->for($batch, 'runBatch')->for($prompt)->for($engine)->create();
    }));

    return [$tenant, $run];
}

function inQueuedTenant(Team $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], $callback));
}

function collectQueued(Team $tenant, Run $run): void
{
    app(TenantContext::class)->run($tenant->id, fn () => app(QueuedEngineCollector::class)->collect($run->id));
}

test('queued provider submission is billed once, survives polling and persists annotations under tenant RLS', function () {
    [$tenant, $run] = queuedFixture();
    $id = '11150800-0000-0000-0000-123456789abc';
    $base = 'https://api.dataforseo.com/v3/ai_optimization/chat_gpt/llm_scraper';
    Http::preventStrayRequests()->fake([
        "$base/task_post" => Http::response(EngineFixtures::load('dataforseo', 'created')),
        "$base/tasks_ready" => Http::sequence()->push(['status_code' => 20000, 'tasks' => []])->push(EngineFixtures::load('dataforseo', 'ready')),
        "$base/task_get/advanced/$id" => Http::response(EngineFixtures::load('chatgpt_ui', 'no-nb')),
    ]);
    $store = Mockery::mock(RawResponseStore::class);
    $store->expects('put')->once()->andReturn('gs://fixture/queued.json');
    app()->instance(RawResponseStore::class, $store);
    collectQueued($tenant, $run);
    inQueuedTenant($tenant, function () use ($run, $id) {
        expect($run->refresh()->status)->toBe('submitted')->and($run->provider_task_id)->toBe($id)
            ->and(UsageEvent::query()->count())->toBe(1)->and(UsageEvent::query()->sole()->cost_usd)->toBe('0.02000000');
    });
    collectQueued($tenant, $run);
    collectQueued($tenant, $run);
    collectQueued($tenant, $run);
    inQueuedTenant($tenant, function () use ($run) {
        expect($run->refresh()->status)->toBe('collected')->and($run->response->annotationUrls())->toHaveCount(2)
            ->and(UsageEvent::query()->count())->toBe(1)->and($run->provider_submissions)->toBe(1);
    });
    Http::assertSentCount(4);
});

test('a pending provider task is resubmitted at six hours only once and both submissions are billed', function () {
    $this->travelTo(now()->startOfDay());
    [$tenant, $run] = queuedFixture();
    Http::preventStrayRequests()->fake([
        '*/task_post' => Http::sequence()->push(EngineFixtures::load('dataforseo', 'created'))->push(EngineFixtures::load('dataforseo', 'resubmitted')),
        '*/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => []]),
    ]);
    collectQueued($tenant, $run);
    $this->travel(6)->hours();
    collectQueued($tenant, $run);
    inQueuedTenant($tenant, function () use ($run) {
        expect($run->refresh()->provider_submissions)->toBe(2)
            ->and($run->provider_task_id)->toBe('11151400-0000-0000-0000-123456789abc')
            ->and(UsageEvent::query()->count())->toBe(2);
    });
    $this->travel(6)->hours();
    expect(fn () => collectQueued($tenant, $run))->toThrow(EngineCallFailed::class, 'provider_task_timeout');
    Http::assertSentCount(4);
});

test('resubmission cannot bypass the fresh monthly quota', function () {
    [$tenant, $run] = queuedFixture(1);
    Http::preventStrayRequests()->fake([
        '*/task_post' => Http::response(EngineFixtures::load('dataforseo', 'created')),
        '*/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => []]),
    ]);
    collectQueued($tenant, $run);
    $this->travel(6)->hours();
    collectQueued($tenant, $run);
    inQueuedTenant($tenant, function () use ($run) {
        expect($run->refresh()->status)->toBe('skipped_quota')->and(UsageEvent::query()->count())->toBe(1);
    });
    Http::assertSentCount(2);
});

test('queued credentials stay encrypted and bound to the submission account after platform key rotation', function () {
    [$tenant, $run] = queuedFixture();
    Http::preventStrayRequests()->fake([
        '*/task_post' => Http::response(EngineFixtures::load('dataforseo', 'created')),
        '*/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => []]),
    ]);
    collectQueued($tenant, $run);
    config(['engines.dataforseo.credentials' => 'rotated:fixture']);
    collectQueued($tenant, $run);
    inQueuedTenant($tenant, function () use ($run) {
        expect($run->refresh()->provider_api_key)->toBe('fixture-login:fixture-password')
            ->and($run->getRawOriginal('provider_api_key'))->not->toContain('fixture-login')
            ->and($run->toArray())->not->toHaveKey('provider_api_key')
            ->and(UsageEvent::query()->sole()->billed_to)->toBe('platform');
    });
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/tasks_ready') && $request->hasHeader('Authorization', 'Basic '.base64_encode('fixture-login:fixture-password')));
});
