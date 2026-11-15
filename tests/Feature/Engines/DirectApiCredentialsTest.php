<?php

use App\Engines\EngineCallFailed;
use App\Engines\EngineCollector;
use App\Engines\RawResponseStore;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\TenantApiKey;
use App\Models\UsageEvent;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Tests\Support\Engines\EngineFixtures;

it('fails without credentials and never uses a disabled tenant key', function (string $engineKey, string $provider, string $error, bool $byokEnabled) {
    Http::preventStrayRequests()->fake();
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->shouldNotReceive('put');
    app()->instance(RawResponseStore::class, $raw);
    config(['engines.'.$engineKey.'.api_key' => null]);
    $tenant = Team::factory()->tenant()->create(['byok_enabled' => $byokEnabled]);
    $engine = Engine::factory()->create(['key' => $engineKey, 'provider' => $provider, 'method' => 'api', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay()]);

    app(TenantContext::class)->run($tenant->id, function () use ($tenant, $engine, $provider, $error, $byokEnabled) {
        $rls = app(TenantRls::class);
        $run = $rls->run([$tenant->id], function () use ($engine, $provider, $byokEnabled) {
            // A stored key must not become usable again after the owner disables BYOK.
            if (! $byokEnabled) {
                TenantApiKey::query()->create(['provider' => $provider, 'key' => 'synthetic-tenant-key', 'last_verified_at' => now()]);
            }
            $market = Market::factory()->create(['country' => 'NO', 'language' => 'nb']);
            $prompt = Prompt::factory()->for($market)->create();
            $batch = RunBatch::factory()->for($market)->create();

            return Run::factory()->for($batch, 'runBatch')->for($prompt)->for($engine)->create();
        });

        expect(fn () => app(EngineCollector::class)->collect($run->id))->toThrow(EngineCallFailed::class, $error);
        Http::assertNothingSent();
        $rls->run([$tenant->id], function () use ($run) {
            expect(UsageEvent::query()->count())->toBe(0)
                ->and($run->response()->exists())->toBeFalse()
                ->and($run->refresh()->quota_reserved_at)->toBeNull();
        });
    });
})->with([
    'OpenAI key missing' => ['chatgpt_api', 'openai', 'openai_missing_api_key', true],
    'OpenAI BYOK disabled' => ['chatgpt_api', 'openai', 'openai_missing_api_key', false],
    'Gemini key missing' => ['gemini_api', 'google', 'gemini_missing_api_key', true],
    'Gemini BYOK disabled' => ['gemini_api', 'google', 'gemini_missing_api_key', false],
]);

it('uses platform keys unless BYOK is enabled with a stored key and charges the matching budget', function (string $engineKey, string $provider, bool $byokEnabled) {
    $fixture = EngineFixtures::load($engineKey, 'no-nb');
    $url = $engineKey === 'chatgpt_api'
        ? 'https://eu.api.openai.com/v1/responses'
        : 'https://generativelanguage.googleapis.com/*';
    Http::preventStrayRequests()->fake([$url => Http::response($fixture)]);
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->shouldReceive('put')->once()->andReturn('gs://synthetic/response.json');
    app()->instance(RawResponseStore::class, $raw);
    config(['engines.'.$engineKey.'.api_key' => 'synthetic-platform-key']);
    $tenant = Team::factory()->tenant()->create(['byok_enabled' => $byokEnabled]);
    $engine = Engine::factory()->create(['key' => $engineKey, 'provider' => $provider, 'method' => 'api', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subDay()]);

    app(TenantContext::class)->run($tenant->id, function () use ($tenant, $engine, $provider, $byokEnabled) {
        $rls = app(TenantRls::class);
        $run = $rls->run([$tenant->id], function () use ($engine, $provider) {
            TenantApiKey::query()->create(['provider' => $provider, 'key' => 'synthetic-tenant-key', 'last_verified_at' => now()]);
            $market = Market::factory()->create(['country' => 'NO', 'language' => 'nb']);
            $prompt = Prompt::factory()->for($market)->create();
            $batch = RunBatch::factory()->for($market)->create();

            return Run::factory()->for($batch, 'runBatch')->for($prompt)->for($engine)->create();
        });
        app(EngineCollector::class)->collect($run->id);
        $rls->run([$tenant->id], function () use ($run, $byokEnabled) {
            expect(UsageEvent::query()->sole()->billed_to)->toBe($byokEnabled ? 'byok' : 'platform')
                ->and($run->refresh()->status)->toBe('collected')
                ->and($run->quota_reserved_at)->toBeNull();
        });
    });

    Http::assertSentCount(1);
    $key = $byokEnabled ? 'synthetic-tenant-key' : 'synthetic-platform-key';
    Http::assertSent(fn ($request) => $engineKey === 'chatgpt_api'
        ? $request->url() === 'https://eu.api.openai.com/v1/responses' && $request->hasHeader('Authorization', 'Bearer '.$key) && $request['store'] === false
        : $request->hasHeader('x-goog-api-key', $key));
})->with([
    'OpenAI platform' => ['chatgpt_api', 'openai', false],
    'OpenAI BYOK' => ['chatgpt_api', 'openai', true],
    'Gemini platform' => ['gemini_api', 'google', false],
    'Gemini BYOK' => ['gemini_api', 'google', true],
]);
