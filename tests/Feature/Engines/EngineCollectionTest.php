<?php

use App\Engines\EngineAdapter;
use App\Engines\EngineCollector;
use App\Engines\EngineRegistry;
use App\Engines\EngineRequest;
use App\Engines\EngineResult;
use App\Engines\RawResponseStore;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Tests\Support\Engines\EngineFixtures;

it('collects a fixture through the engine registry and records exactly one response and usage event', function (?string $apiKey, string $billedTo) {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'));

    $tenant = Team::factory()->tenant()->create();
    $otherTenant = Team::factory()->tenant()->create();
    $engine = Engine::factory()->create(['key' => 'dummy', 'is_enabled' => true]);
    $fixture = EngineFixtures::load('dummy', 'no-nb');

    EnginePrice::factory()->for($engine)->create([
        'valid_from' => now()->subDays(2),
        'price_per_call_usd' => '1.00000000',
    ]);
    EnginePrice::factory()->for($engine)->create([
        'valid_from' => now()->subDay(),
        'price_per_call_usd' => '0.02000000',
        'price_per_1k_input_tokens_usd' => '1.50000000',
        'price_per_1k_output_tokens_usd' => '2.50000000',
        'price_per_search_usd' => '0.00100000',
    ]);
    EnginePrice::factory()->for($engine)->create([
        'valid_from' => now()->addDay(),
        'price_per_call_usd' => '100.00000000',
    ]);

    $adapter = Mockery::mock(EngineAdapter::class);
    $adapter->expects('key')->andReturn('dummy');
    $adapter->expects('supports')->with(Mockery::on(fn (Market $market): bool => $market->country === 'NO' && $market->language === 'nb'))->andReturnTrue();
    $rawStore = Mockery::mock(RawResponseStore::class);
    app()->instance(EngineRegistry::class, new EngineRegistry([$adapter]));
    app()->instance(RawResponseStore::class, $rawStore);

    $tenancy = app(TenantContext::class);
    $rls = app(TenantRls::class);

    $run = $tenancy->run($tenant->id, function () use ($engine) {
        $market = Market::factory()->create(['country' => 'NO', 'language' => 'nb', 'location_label' => 'Oslo']);
        $prompt = Prompt::factory()->for($market)->create(['text' => 'Finn et eksempel.']);
        $batch = RunBatch::factory()->for($market)->create();

        return Run::factory()->for($batch, 'runBatch')->for($prompt)->for($engine)->create();
    });

    $adapter->expects('collect')->with(Mockery::on(fn (EngineRequest $request): bool => $request->promptText === 'Finn et eksempel.'
        && $request->runId === (string) $run->id
        && $request->country === 'NO'
        && $request->language === 'nb'
        && $request->city === 'Oslo'
        && $request->apiKey === $apiKey
    ))->andReturn(new EngineResult(
        answerText: $fixture['answer'],
        modelIdReported: $fixture['model'],
        citations: $fixture['sources'],
        inputTokens: $fixture['usage']['input_tokens'],
        outputTokens: $fixture['usage']['output_tokens'],
        searchCalls: $fixture['usage']['search_calls'],
        raw: $fixture,
    ));

    $uri = "gs://ekko-raw/{$tenant->id}/2026/11/{$run->id}.json";
    $rawStore->expects('put')->with(
        $tenant->id,
        $run->id,
        Mockery::on(fn (CarbonImmutable $timestamp): bool => $timestamp->format('Y-m-d H:i:s') === '2026-11-15 12:00:00'),
        $fixture,
    )->andReturn($uri);

    $collector = app(EngineCollector::class);
    $tenancy->run($otherTenant->id, function () use ($collector, $run) {
        expect(fn () => $collector->collect($run->id))->toThrow(ModelNotFoundException::class);
    });

    $tenancy->run($tenant->id, function () use ($tenant, $rls, $collector, $run, $apiKey, $billedTo, $fixture, $uri) {
        $collected = $collector->collect($run->id, $apiKey);
        $replay = $collector->collect($run->id, $apiKey);
        $rls->run([$tenant->id], function () use ($run, $collected, $replay, $billedTo, $fixture, $uri) {
            $response = Response::query()->sole();
            $usage = UsageEvent::query()->sole();

            expect($collected->response->id)->toBe($response->id)
                ->and($collected->result?->citations)->toBe($fixture['sources'])
                ->and($response->answer_text)->toBe($fixture['answer'])
                ->and($response->model_id_reported)->toBe('dummy-1')
                ->and($response->raw_uri)->toBe($uri)
                ->and($response->cost_usd)->toBe('0.32700000')
                ->and($usage->run_id)->toBe($run->id)
                ->and($usage->kind)->toBe('engine_call')
                ->and($usage->calls)->toBe(1)
                ->and($usage->input_tokens)->toBe(120)
                ->and($usage->output_tokens)->toBe(50)
                ->and($usage->search_calls)->toBe(2)
                ->and($usage->cost_usd)->toBe('0.32700000')
                ->and($usage->billed_to)->toBe($billedTo)
                ->and($run->refresh()->status)->toBe('collected');

            expect($replay->response->id)->toBe($response->id)
                ->and($replay->result)->toBeNull()
                ->and(UsageEvent::query()->count())->toBe(1);
        });
    });
})->with([
    'platform key' => [null, 'platform'],
    'own key' => ['synthetic-key', 'byok'],
]);
