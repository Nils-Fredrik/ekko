<?php

// T25 – monthly cost estimate for the engine setup (docs/spec.md 7.4 and 13, step 4): engine prices, the plan,
// BYOK and this month's usage. It is an estimate and writes nothing.

use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\TenantApiKey;
use App\Models\UsageEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/Onboarding/helpers.php';

/** An API engine at 0.01 per call and 0.01 per search (two searches a call: 0.03), and a scrape engine at 0.002 per call. */
function estimateEngines(): void
{
    $api = Engine::factory()->create(['key' => 'est_api', 'method' => 'api', 'provider' => 'anthropic']);
    EnginePrice::factory()->create(['engine_key' => $api->key, 'price_per_call_usd' => '0.01', 'price_per_search_usd' => '0.01']);
    $scrape = Engine::factory()->create(['key' => 'est_scrape', 'method' => 'ui_scrape', 'provider' => 'dataforseo']);
    EnginePrice::factory()->create(['engine_key' => $scrape->key, 'price_per_call_usd' => '0.002']);
}

function estimate(object $test, object $owner, array $data): TestResponse
{
    return $test->actingAs($owner)->postJson('/onboarding/cost-estimate', $data);
}

it('estimates calls and cost per engine, and the extraction of every answer', function () {
    estimateEngines();
    $plan = Plan::factory()->create(['allowed_engines' => ['est_api', 'est_scrape'], 'monthly_call_quota' => null, 'max_prompts_per_market' => 50]);
    [, $owner] = wizardTenant($plan);

    estimate($this, $owner, [
        'prompt_count' => 30,
        'core_prompt_count' => 10,
        'engines' => [
            ['engine_key' => 'est_api', 'frequency' => 'daily'],
            ['engine_key' => 'est_scrape', 'frequency' => 'weekly', 'prompt_scope' => 'core'],
        ],
    ])->assertOk()
        // est_api: 30 prompts * 30 runs = 900 calls at 0.03 = 27.00. est_scrape: 10 core * 4.35 = 43.5, rounded up to 44 calls at 0.002 = 0.09.
        ->assertJsonPath('engines', [
            ['engine_key' => 'est_api', 'calls' => 900, 'cost_usd' => '27.00', 'billed_to' => 'platform'],
            ['engine_key' => 'est_scrape', 'calls' => 44, 'cost_usd' => '0.09', 'billed_to' => 'platform'],
        ])
        ->assertJsonPath('calls', 944)
        // Extraction per answer: (1500 * 0.10 + 400 * 0.50) / 1e6 * 0.5 = 0.000175; 944 answers = 0.1652.
        ->assertJsonPath('extraction_cost_usd', '0.17')
        ->assertJsonPath('engine_cost_usd', '27.09')
        ->assertJsonPath('total_cost_usd', '27.26')
        ->assertJsonPath('platform_cost_usd', '27.26')
        ->assertJsonPath('byok_cost_usd', '0.00')
        ->assertJsonPath('issues', [])
        ->assertJsonPath('prompt_count', 30)
        ->assertJsonPath('core_prompt_count', 10);
});

it('counts a core prompt on the engine\'s core row only, as the dispatcher does', function () {
    estimateEngines();
    $plan = Plan::factory()->create(['allowed_engines' => ['est_api', 'est_scrape'], 'monthly_call_quota' => null, 'max_prompts_per_market' => 50]);
    [, $owner] = wizardTenant($plan);

    // est_api has a core row (daily) and an all row (weekly): the 10 core prompts run daily, the 40 others weekly.
    // est_scrape has only an all row, so it runs all 50.
    estimate($this, $owner, [
        'prompt_count' => 50,
        'core_prompt_count' => 10,
        'engines' => [
            ['engine_key' => 'est_api', 'frequency' => 'daily', 'prompt_scope' => 'core'],
            ['engine_key' => 'est_api', 'frequency' => 'weekly', 'prompt_scope' => 'all'],
            ['engine_key' => 'est_scrape', 'frequency' => 'monthly', 'prompt_scope' => 'all'],
        ],
    ])->assertOk()
        // 10 * 30 = 300, 40 * 4.35 = 174 (not 50 * 4.35 = 218), 50 * 1 = 50.
        ->assertJsonPath('engines.0.calls', 300)
        ->assertJsonPath('engines.1.calls', 174)
        ->assertJsonPath('engines.2.calls', 50)
        ->assertJsonPath('calls', 524);
});

it('multiplies by repetitions and counts nothing for an engine that is off', function () {
    estimateEngines();
    $plan = Plan::factory()->create(['allowed_engines' => ['est_api', 'est_scrape'], 'monthly_call_quota' => null, 'max_repetitions' => 3]);
    [, $owner] = wizardTenant($plan);

    estimate($this, $owner, [
        'prompt_count' => 10,
        'engines' => [
            ['engine_key' => 'est_api', 'frequency' => 'monthly', 'repetitions' => 3],
            ['engine_key' => 'est_scrape', 'frequency' => 'off'],
        ],
    ])->assertOk()
        ->assertJsonPath('engines.0.calls', 30)
        ->assertJsonPath('engines.0.cost_usd', '0.90')
        ->assertJsonPath('engines.1.calls', 0)
        ->assertJsonPath('engines.1.cost_usd', '0.00')
        ->assertJsonPath('issues', []);
});

it('bills an API engine to the tenant own key when it has one', function () {
    estimateEngines();
    $plan = Plan::factory()->create(['allowed_engines' => ['est_api'], 'monthly_call_quota' => null]);
    [$tenant, $owner] = wizardTenant($plan);
    $tenant->forceFill(['byok_enabled' => true])->save();
    inTenant($tenant, fn () => TenantApiKey::query()->create(['provider' => 'anthropic', 'key' => 'sk-not-a-real-key', 'last_verified_at' => now()]));

    estimate($this, $owner, ['prompt_count' => 10, 'engines' => [['engine_key' => 'est_api', 'frequency' => 'weekly']]])
        ->assertOk()
        ->assertJsonPath('engines.0.billed_to', 'byok')
        // 10 * 4.35 = 43.5, 44 calls at 0.03 = 1.32 on the tenant's key; the extraction is the platform's.
        ->assertJsonPath('byok_cost_usd', '1.32')
        ->assertJsonPath('platform_cost_usd', '0.01')
        ->assertJsonMissingPath('engines.0.key');
});

it('warns when the setup is outside the plan', function () {
    estimateEngines();
    $plan = Plan::factory()->create([
        'allowed_engines' => ['est_scrape'],
        'engine_max_frequencies' => ['est_scrape' => 'weekly'],
        'max_frequency' => 'weekly',
        'max_repetitions' => 1,
        'max_prompts_per_market' => 20,
        'monthly_call_quota' => null,
    ]);
    [, $owner] = wizardTenant($plan);

    $response = estimate($this, $owner, [
        'prompt_count' => 30,
        'engines' => [
            ['engine_key' => 'est_api', 'frequency' => 'daily'],
            ['engine_key' => 'est_scrape', 'frequency' => 'daily', 'repetitions' => 2],
            ['engine_key' => 'finnes_ikke', 'frequency' => 'daily'],
        ],
    ])->assertOk();

    $codes = collect($response->json('issues'))->map(fn ($i) => $i['code'].':'.($i['engine_key'] ?? ''))->all();
    expect($codes)->toEqualCanonicalizing([
        'prompts_above_plan:',
        'engine_not_in_plan:est_api',
        'frequency_above_plan:est_api',
        'frequency_above_plan:est_scrape',
        'repetitions_above_plan:est_scrape',
        'unknown_engine:finnes_ikke',
    ]);
    // Every issue has a message in the user language, with the engine filled in.
    expect(collect($response->json('issues'))->firstWhere('code', 'engine_not_in_plan')['message'])->toBe(__('onboarding.issues.engine_not_in_plan', ['engine' => 'est_api']));
});

it('warns when the estimate and this month\'s usage exceed the call quota or the budget', function () {
    estimateEngines();
    $plan = Plan::factory()->create(['allowed_engines' => ['est_api'], 'monthly_call_quota' => 100, 'monthly_budget_usd' => '10.00', 'max_prompts_per_market' => 50]);
    [$tenant, $owner] = wizardTenant($plan);
    inTenant($tenant, fn () => UsageEvent::factory()->create(['kind' => 'engine_call', 'calls' => 60, 'cost_usd' => '9.00', 'billed_to' => 'platform', 'occurred_at' => now()]));

    // 60 + 20 calls is below the quota of 100, and 9.00 + 0.60 + extraction is below the budget of 10.
    $response = estimate($this, $owner, ['prompt_count' => 20, 'engines' => [['engine_key' => 'est_api', 'frequency' => 'monthly']]])->assertOk();
    expect($response->json('issues'))->toBe([]);

    $response = estimate($this, $owner, ['prompt_count' => 50, 'engines' => [['engine_key' => 'est_api', 'frequency' => 'monthly']]])->assertOk();
    // 60 + 50 = 110 calls, and 9.00 + 1.50 = 10.50 USD.
    expect(array_column($response->json('issues'), 'code'))->toContain('exceeds_call_quota')->toContain('exceeds_budget');
});

it('takes the prompt counts from an existing market, unless the request gives them', function () {
    estimateEngines();
    $plan = Plan::factory()->create(['allowed_engines' => ['est_api'], 'monthly_call_quota' => null]);
    [$tenant, $owner] = wizardTenant($plan);
    $market = inTenant($tenant, function () {
        $market = Market::factory()->create();
        Prompt::factory()->count(4)->for($market)->create(['priority' => 'standard']);
        Prompt::factory()->count(2)->for($market)->create(['priority' => 'core']);
        Prompt::factory()->for($market)->create(['is_active' => false, 'priority' => 'core']);

        return $market;
    });
    $engines = [['engine_key' => 'est_api', 'frequency' => 'monthly', 'prompt_scope' => 'core']];

    estimate($this, $owner, ['market_id' => $market->id, 'engines' => $engines])
        ->assertOk()->assertJsonPath('prompt_count', 6)->assertJsonPath('core_prompt_count', 2)->assertJsonPath('engines.0.calls', 2);

    estimate($this, $owner, ['market_id' => $market->id, 'prompt_count' => 20, 'engines' => $engines])
        ->assertOk()->assertJsonPath('prompt_count', 20)->assertJsonPath('core_prompt_count', 2);
});

it('does not read the market of another tenant', function () {
    estimateEngines();
    [$tenantA, $ownerA] = wizardTenant();
    [$tenantB] = wizardTenant();
    $marketB = inTenant($tenantB, fn () => Market::factory()->create());

    estimate($this, $ownerA, ['market_id' => $marketB->id, 'engines' => [['engine_key' => 'est_api', 'frequency' => 'daily']]])->assertNotFound();
});

it('writes nothing', function () {
    estimateEngines();
    $plan = Plan::factory()->create(['allowed_engines' => ['est_api'], 'monthly_call_quota' => null]);
    [$tenant, $owner] = wizardTenant($plan);
    $tables = ['usage_events', 'usage_monthly', 'markets', 'market_engines', 'prompts', 'brands', 'activity_log'];
    $before = collect($tables)->map(fn ($t) => DB::table($t)->count())->all();

    estimate($this, $owner, ['prompt_count' => 10, 'engines' => [['engine_key' => 'est_api', 'frequency' => 'daily']]])->assertOk();

    expect(collect($tables)->map(fn ($t) => DB::table($t)->count())->all())->toBe($before);
});

it('reports an engine without a price as an issue and leaves it out of the sum', function () {
    Engine::factory()->create(['key' => 'est_noprice', 'method' => 'api']);
    $plan = Plan::factory()->create(['allowed_engines' => ['est_noprice'], 'monthly_call_quota' => null]);
    [, $owner] = wizardTenant($plan);

    estimate($this, $owner, ['prompt_count' => 10, 'engines' => [['engine_key' => 'est_noprice', 'frequency' => 'daily']]])
        ->assertOk()
        ->assertJsonPath('engines', [])
        ->assertJsonPath('issues.0.code', 'no_price')->assertJsonMissingPath('issues.0.market_id')
        ->assertJsonPath('calls', 0)->assertJsonPath('total_cost_usd', '0.00')
        ->assertJsonPath('markets.0.total_cost_usd', null);
});

it('validates the setup', function (array $data, string $field) {
    estimateEngines();
    [, $owner] = wizardTenant();

    estimate($this, $owner, $data + ['prompt_count' => 10, 'engines' => [['engine_key' => 'est_api', 'frequency' => 'daily']]])
        ->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no engines' => [['engines' => []], 'engines'],
    'too many engines' => [['engines' => array_fill(0, 21, ['engine_key' => 'a', 'frequency' => 'daily'])], 'engines'],
    'bad frequency' => [['engines' => [['engine_key' => 'est_api', 'frequency' => 'hourly']]], 'engines.0.frequency'],
    'bad scope' => [['engines' => [['engine_key' => 'est_api', 'frequency' => 'daily', 'prompt_scope' => 'some']]], 'engines.0.prompt_scope'],
    'too many repetitions' => [['engines' => [['engine_key' => 'est_api', 'frequency' => 'daily', 'repetitions' => 4]]], 'engines.0.repetitions'],
    'negative prompts' => [['prompt_count' => -1], 'prompt_count'],
    'absurd prompts' => [['prompt_count' => 100000], 'prompt_count'],
]);

it('needs a prompt count or a market', function () {
    [, $owner] = wizardTenant();

    estimate($this, $owner, ['engines' => [['engine_key' => 'est_api', 'frequency' => 'daily']]])->assertStatus(422)->assertJsonValidationErrors('prompt_count');
});

it('estimates both multiweek intervals using the engine cap and monthly factors', function (string $frequency, int $answers, array $issues) {
    estimateEngines();
    $plan = Plan::factory()->create(['max_prompts_per_market' => 50, 'allowed_engines' => ['est_api'], 'engine_max_frequencies' => ['est_api' => 'every_21_days']]);
    [, $owner] = wizardTenant($plan);

    estimate($this, $owner, [
        'prompt_count' => 50,
        'core_prompt_count' => 10,
        'engines' => [['engine_key' => 'est_api', 'frequency' => $frequency]],
    ])->assertOk()->assertJsonPath('engines.0.calls', $answers)->assertJsonPath('issues.*.code', $issues);
})->with([
    ['every_14_days', 109, ['frequency_above_plan']],
    ['every_21_days', 73, []],
]);
