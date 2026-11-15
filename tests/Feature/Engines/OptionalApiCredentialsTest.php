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

it('never substitutes platform credentials for an optional API without enabled tenant credentials', function (string $engineKey, string $provider, string $error, bool $byokEnabled) {
    Http::preventStrayRequests()->fake();
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->shouldNotReceive('put');
    app()->instance(RawResponseStore::class, $raw);
    config(['engines.'.$engineKey.'.api_key' => 'synthetic-platform-key']);
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
