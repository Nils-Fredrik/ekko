<?php

use App\Engines\Credentials\ApiKeyProvider;
use App\Engines\Credentials\TenantApiKeys;
use App\Engines\EngineCollector;
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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Engines\EngineFixtures;

test('collection uses the verified tenant key and stops at its BYOK budget between rollups', function () {
    $this->travelTo(now()->parse('2026-11-15T12:00:00Z'));
    $fixture = EngineFixtures::load('chatgpt_api', 'no-nb');
    Http::preventStrayRequests()->fake([
        'https://api.openai.com/v1/models' => Http::response(['data' => []]),
        'https://api.openai.com/v1/responses' => Http::response($fixture),
    ]);
    $rawStore = Mockery::mock(RawResponseStore::class);
    $rawStore->expects('put')->once()->andReturn('gs://fixture/collected.json');
    app()->instance(RawResponseStore::class, $rawStore);

    $plan = Plan::factory()->create(['monthly_budget_usd' => '0.00']);
    $tenant = Team::factory()->tenant()->withPlan($plan)->create([
        'byok_enabled' => true,
        'monthly_byok_budget_usd' => '1.00',
    ]);
    $engine = Engine::factory()->create([
        'key' => 'chatgpt_api',
        'method' => 'api',
        'provider' => 'openai',
        'is_enabled' => true,
    ]);
    EnginePrice::factory()->for($engine)->create([
        'valid_from' => now()->subDay(),
        'price_per_call_usd' => '1.00',
        'price_per_1k_input_tokens_usd' => '0',
        'price_per_1k_output_tokens_usd' => '0',
        'price_per_search_usd' => '0',
    ]);

    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], function () use ($tenant, $engine) {
        app(TenantApiKeys::class)->store($tenant->owner, ApiKeyProvider::OpenAi, 'verified-test-key');
        $market = Market::factory()->create(['country' => 'NO', 'language' => 'nb']);
        $prompt = Prompt::factory()->for($market)->create(['priority' => 'core']);
        $batch = RunBatch::factory()->for($market)->create();
        [$first, $next] = Run::factory()->for($batch, 'runBatch')->for($prompt)->for($engine)->createMany([
            ['repetition' => 1],
            ['repetition' => 2],
        ]);

        $collector = app(EngineCollector::class);
        expect($collector->collect($first->id))->not->toBeNull()
            ->and(UsageEvent::query()->sole()->billed_to)->toBe('byok')
            ->and($collector->collect($next->id))->toBeNull()
            ->and($next->refresh()->status)->toBe('skipped_quota')
            ->and($collector->collect($next->id))->toBeNull()
            ->and(UsageEvent::query()->count())->toBe(1);
    }));

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
        && $request->hasHeader('Authorization', 'Bearer verified-test-key'));
});
