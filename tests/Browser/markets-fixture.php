<?php

use App\MeasurementPlan\MeasurementPlans;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

// Two markets and a priced engine, so the plan matrix can be read without scrolling.
$marketsPlan = Plan::factory()->create([
    'name' => 'Markets fixture plan',
    'allowed_engines' => ['chatgpt_api', 'gemini_api', 'claude_haiku', 'browser_wizard', 'filter_engine', 'portfolio_engine'],
    'max_frequency' => 'daily',
    'max_repetitions' => 2,
    'max_markets_per_brand' => 4,
    'max_brands' => 3,
    'max_prompts_per_market' => 30,
    'monthly_call_quota' => 10000,
    'monthly_budget_usd' => '1000.00',
]);
$marketsTeam = Team::factory()->tenant()->withPlan($marketsPlan)->create(['name' => 'Markets fixture']);
$marketsUser = $marketsTeam->owner;
$marketsUser->forceFill([
    'email' => 'browser-markets@example.test',
    'password' => Hash::make($password),
    'locale' => 'en',
])->save();
$marketsUser->switchTeam($marketsTeam);

return app(TenantContext::class)->run($marketsTeam->id, function () use ($marketsUser): array {
    $brand = Brand::factory()->create(['name' => 'Markets fixture brand', 'primary_domain' => 'markets.example.test']);
    $plans = app(MeasurementPlans::class);
    $plans->initialize($brand, [[
        'engine_key' => 'chatgpt_api',
        'frequency' => 'weekly',
        'prompt_scope' => 'all',
        'repetitions' => 1,
    ]]);
    foreach ([['NO', 'nb'], ['SE', 'sv']] as [$country, $language]) {
        $market = Market::factory()->for($brand)->create([
            'country' => $country,
            'language' => $language,
            'location_label' => null,
            'is_active' => true,
        ]);
        $plans->inherit($market);
        Prompt::factory()->for($market)->create(['is_active' => true, 'priority' => 'core', 'text' => "Core prompt {$country}"]);
        Prompt::factory()->for($market)->create(['is_active' => true, 'priority' => 'standard', 'text' => "Standard prompt {$country}"]);
    }

    return ['U24_EMAIL' => $marketsUser->email, 'U24_BRAND_ID' => (string) $brand->id];
});
