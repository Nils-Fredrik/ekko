<?php

use App\Engines\Adapters\GeminiApiAdapter;
use App\Engines\EngineCallFailed;
use App\Engines\EngineRequest;
use App\Engines\QueuedEngineCollector;
use App\Engines\RawResponseStore;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Database\Seeders\ProductionEngineSeeder;
use Illuminate\Support\Facades\Http;

it('seeds direct production engines without demo prices and preserves operator choices', function () {
    Engine::factory()->create(['key' => 'perplexity_api', 'provider' => 'perplexity']);
    config(['engines.gemini_grounding_approved' => false]);
    $this->seed(ProductionEngineSeeder::class);
    expect(Engine::query()->where('is_enabled', true)->pluck('key')->sort()->values()->all())
        ->toBe(['chatgpt_api', 'claude_haiku', 'claude_sonnet'])
        ->and(Engine::query()->count())->toBe(9)
        ->and(Engine::query()->whereIn('provider', ['dataforseo', 'perplexity'])->where('is_enabled', true)->exists())->toBeFalse()
        ->and(EnginePrice::query()->count())->toBe(0);
    Engine::query()->findOrFail('chatgpt_api')->update(['model_id' => 'operator-model', 'is_enabled' => false]);
    $this->seed(ProductionEngineSeeder::class);
    expect(Engine::query()->findOrFail('chatgpt_api')->model_id)->toBe('operator-model')
        ->and(Engine::query()->findOrFail('chatgpt_api')->is_enabled)->toBeFalse();
});

it('blocks Gemini in production before transmitting either platform or tenant credentials', function (?string $key) {
    $this->app['env'] = 'production';
    config(['engines.gemini_grounding_approved' => false, 'engines.gemini_api.api_key' => 'synthetic-platform-key']);
    Http::preventStrayRequests()->fake();
    expect((new Engine(['key' => 'gemini_api', 'provider' => 'google', 'is_enabled' => true]))->isAvailable())->toBeFalse()
        ->and(fn () => app(GeminiApiAdapter::class)->collect(new EngineRequest('Synthetic', 'NO', 'nb', null, '1', $key)))
        ->toThrow(EngineCallFailed::class, 'gemini_grounding_not_approved');
    Http::assertNothingSent();
    config(['engines.gemini_grounding_approved' => true]);
    expect((new Engine(['key' => 'gemini_api', 'provider' => 'google', 'is_enabled' => true]))->isAvailable())->toBeTrue();
})->with([null, 'synthetic-tenant-key']);

it('stops polling already submitted tasks from a disabled provider', function () {
    config(['engines.disabled_providers' => ['dataforseo', 'perplexity']]);
    Http::preventStrayRequests()->fake();
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->shouldNotReceive('put');
    app()->instance(RawResponseStore::class, $raw);
    $tenant = Team::factory()->tenant()->create();
    $engine = Engine::factory()->create(['key' => 'chatgpt_ui', 'provider' => 'dataforseo', 'method' => 'ui_scrape']);
    app(TenantContext::class)->run($tenant->id, function () use ($tenant, $engine) {
        $run = app(TenantRls::class)->run([$tenant->id], function () use ($engine) {
            $market = Market::factory()->create();
            $prompt = Prompt::factory()->for($market)->create();
            $batch = RunBatch::factory()->for($market)->create();

            return Run::factory()->for($batch, 'runBatch')->for($prompt)->for($engine)->create([
                'status' => 'submitted', 'provider_task_id' => 'synthetic-task', 'provider_submissions' => 1,
            ]);
        });
        expect(fn () => app(QueuedEngineCollector::class)->collect($run->id))->toThrow(EngineCallFailed::class, 'engine_disabled');
    });
    Http::assertNothingSent();
    expect($engine->isAvailable())->toBeFalse()
        ->and((new Engine(['provider' => 'perplexity', 'is_enabled' => true]))->isAvailable())->toBeFalse()
        ->and(Engine::query()->find($engine->key))->not->toBeNull();
});
