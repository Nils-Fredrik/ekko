<?php

use App\Models\AggDaily;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Engine;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

// Loaded only by seed.php after its dedicated-test-database guard.
$filterTeam = Team::factory()->tenant()->create();
$filterUser = $filterTeam->owner;
$filterUser->forceFill(['email' => 'browser-filters@example.test', 'password' => Hash::make($password), 'locale' => 'en'])->save();
$filterUser->switchTeam($filterTeam);

return app(TenantContext::class)->run($filterTeam->id, function () use ($filterUser): array {
    $brand = Brand::factory()->create(['name' => 'Filter fixture']);
    $competitor = Competitor::factory()->create(['brand_id' => $brand->id, 'name' => 'Comparison fixture']);
    $engine = Engine::factory()->create(['key' => 'filter_engine', 'provider' => 'openai']);
    $ids = [];
    foreach (['NO', 'SE'] as $index => $country) {
        $market = Market::factory()->create(['brand_id' => $brand->id, 'country' => $country, 'location_label' => null]);
        $topic = Topic::factory()->create(['brand_id' => $brand->id, 'name' => 'Topic '.($index + 1)]);
        $prompt = Prompt::factory()->create(['market_id' => $market->id, 'topic_id' => $topic->id, 'text' => 'Filter prompt '.($index + 1)]);
        MarketEngine::factory()->create(['market_id' => $market->id, 'engine_key' => $engine->key]);
        foreach (['2026-10-01', '2026-10-02', '2025-10-01'] as $date) {
            AggDaily::factory()->create(['brand_id' => $brand->id, 'market_id' => $market->id, 'engine_key' => $engine->key,
                'prompt_id' => $prompt->id, 'date' => $date, 'responses' => 20, 'brand_mentioned' => 8 + $index,
                'competitor_mentions' => [(string) $competitor->id => 3]]);
        }
        $ids[] = (string) $topic->id;
    }

    return ['FILTER_EMAIL' => $filterUser->email, 'FILTER_BRAND_ID' => (string) $brand->id,
        'FILTER_TOPIC_ID' => $ids[0], 'FILTER_COMPETITOR_ID' => (string) $competitor->id];
});
