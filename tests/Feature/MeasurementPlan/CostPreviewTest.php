<?php

use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\RunBatch;
use App\Models\TenantApiKey;
use App\Models\UsageEvent;
use App\Scheduling\PromptSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/MeasurementPlan/helpers.php';

test('an unsaved brand draft prices every active market and counts collection and extraction against one quota', function () {
    Http::preventStrayRequests();
    Queue::fake();
    $engines = planCatalogue();
    [$tenant, $owner] = planTenant(['monthly_call_quota' => 1100, 'monthly_budget_usd' => '100', 'max_prompts_per_market' => 50]);
    $tenant->forceFill(['byok_enabled' => true, 'monthly_byok_budget_usd' => '1.00'])->save();
    $rules = array_map(fn ($key) => planRule($key, 'every_14_days', $key === 'claude_sonnet' ? 'core' : 'all'), array_keys($engines));
    $world = planWorld($tenant, [planRule('chatgpt_api', 'weekly')], []);
    inTenant($tenant, function () use ($world) {
        foreach ([$world['a'], $world['b']] as $market) {
            Prompt::factory()->for($market)->count(9)->create(['is_active' => true, 'priority' => 'core']);
            Prompt::factory()->for($market)->count(39)->create(['is_active' => true, 'priority' => 'standard']);
            Prompt::factory()->for($market)->create(['is_active' => false]);
        }
        Market::factory()->for($world['brand'])->create(['is_active' => false, 'plan_mode' => 'inherit']);
        UsageEvent::factory()->create(['kind' => 'engine_call', 'calls' => 100, 'billed_to' => 'platform', 'occurred_at' => now()]);
        TenantApiKey::query()->create(['provider' => 'openai', 'key' => 'synthetic-test-key', 'last_verified_at' => now()]);
    });
    $state = fn () => inTenant($tenant, fn () => [
        storedStandard($world['brand']), storedMarketRules($world['a']), storedMarketRules($world['b']),
        $world['b']->fresh()->plan_mode,
        ...array_map(fn ($table) => DB::table($table)->count(), ['jobs', 'run_batches', 'runs', 'usage_events', 'usage_monthly', 'activity_log']),
    ]);
    $before = $state();
    $url = route('brands.plan.estimate', $world['brand']);
    $draft = ['standard' => ['engines' => $rules], 'markets' => [['id' => $world['b']->id, 'plan_mode' => 'inherit']]];
    $result = $this->actingAs($owner)->postJson($url, $draft)->assertOk()
        ->assertJsonPath('answers', 698)->assertJsonPath('quota_calls', 1396)
        ->assertJsonPath('markets.0.answers', 349)->assertJsonPath('markets.1.answers', 349)
        ->assertJsonPath('markets.2.answers', 0)->assertJsonPath('markets.2.quota_calls', 0)
        ->assertJsonPath('quota.remaining', 1000)->assertJsonPath('quota.used', 100)
        ->assertJsonPath('engine_cost_usd', '6.98')->assertJsonPath('extraction_cost_usd', '0.12')
        ->assertJsonPath('byok_cost_usd', '2.18')->assertJsonPath('platform_cost_usd', '4.92')
        ->assertJsonPath('total_cost_usd', '7.10');
    expect(array_column($result->json('issues'), 'code'))->toEqualCanonicalizing(['exceeds_call_quota', 'exceeds_byok_budget']);
    $sonnet = collect($result->json('markets.0.rules'))->firstWhere('engine_key', 'claude_sonnet');
    expect($sonnet['answers'])->toBe(22)->and($sonnet['sample_prompt_id'])->toBe($world['prompts']['a_core']->id)
        ->and(PromptSchedule::due($sonnet['sample_prompt_id'], 'every_14_days', CarbonImmutable::parse($sonnet['next_due_on'], $tenant->timezone)))->toBeTrue()
        ->and($sonnet['usage_per_answer']['search_calls'])->toBe(2);

    $draft['markets'][0] = ['id' => $world['b']->id, 'plan_mode' => 'own', 'engines' => array_map(fn ($rule) => array_replace($rule, ['frequency' => 'monthly']), $rules)];
    $this->postJson($url, $draft)->assertOk()->assertJsonPath('markets.1.answers', 160)
        ->assertJsonPath('answers', 509)->assertJsonPath('quota_calls', 1018);
    expect($state())->toEqual($before);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('a disabled core override wins and a new wizard market has no invented example ID', function () {
    planCatalogue();
    [$tenant, $owner] = planTenant();
    $world = planWorld($tenant, [planRule('chatgpt_api', 'daily'), planRule('chatgpt_api', 'off', 'core')], []);
    inTenant($tenant, fn () => RunBatch::factory()->for($world['a'])->create(['dispatch_date' => now('UTC')->toDateString()]));
    $this->actingAs($owner)->postJson(route('brands.plan.estimate', $world['brand']), [
        'new_market' => ['plan_mode' => 'inherit', 'prompt_count' => 8, 'core_prompt_count' => 3],
    ])->assertOk()->assertJsonPath('markets.0.rules.0.sample_prompt_id', $world['prompts']['a_standard']->id)
        ->assertJsonPath('markets.0.rules.0.next_due_on', now('UTC')->addDay()->toDateString())
        ->assertJsonPath('markets.0.rules.1.next_due_on', null)
        ->assertJsonPath('markets.2.rules.0.next_due_on', null)
        ->assertJsonPath('markets.2.answers', 150)->assertJsonPath('answers', 180);
});

test('an unavailable price is identified at its engine and cannot be saved', function () {
    planCatalogue();
    [$tenant, $owner] = planTenant();
    $rule = planRule('claude_haiku', 'monthly');
    $world = planWorld($tenant, [$rule], []);
    EnginePrice::query()->where('engine_key', 'claude_haiku')->delete();
    $draft = ['standard' => ['engines' => [$rule]]];
    $this->actingAs($owner)->postJson(route('brands.plan.estimate', $world['brand']))
        ->assertOk()->assertJsonPath('issues.0.code', 'no_price')->assertJsonPath('issues.0.engine_key', 'claude_haiku')
        ->assertJsonPath('total_cost_usd', null)->assertJsonPath('answers', 2);
    $this->postJson(route('brands.plan.estimate', $world['brand']), $draft)->assertUnprocessable()->assertJsonValidationErrors('standard.engines.0.engine_key');
    $this->putJson(route('brands.plan.update', $world['brand']), $draft)->assertUnprocessable()->assertJsonValidationErrors('standard.engines.0.engine_key');
    $this->postJson(route('brands.plan.estimate', $world['brand']), ['standard' => ['engines' => [planRule('claude_haiku', 'off')]]])
        ->assertOk()->assertJsonPath('answers', 0)->assertJsonPath('total_cost_usd', '0.00')->assertJsonPath('issues', []);
});

test('a preview rejects foreign market IDs even inside an otherwise valid draft', function () {
    planCatalogue();
    [$tenant, $owner] = planTenant();
    [$other] = planTenant();
    $mine = planWorld($tenant, [], []);
    $theirs = planWorld($other, [], []);
    $this->actingAs($owner)->postJson(route('brands.plan.estimate', $mine['brand']), [
        'markets' => [['id' => $theirs['b']->id, 'plan_mode' => 'own', 'engines' => []]],
    ])->assertUnprocessable()->assertJsonValidationErrors('markets.0.id');
    $this->postJson(route('brands.plan.estimate', $theirs['brand']))->assertNotFound();
});

test('the new brand wizard uses the same per-rule rounding, quota and pricing as an existing brand', function (string $frequency, int $answers) {
    planCatalogue();
    [, $owner] = planTenant(['monthly_call_quota' => 15]);
    $this->actingAs($owner)->postJson(route('onboarding.cost-estimate'), [
        'standard' => ['engines' => [planRule('gemini_api', $frequency, 'core', 2)]],
        'prompt_count' => 10, 'core_prompt_count' => 3,
    ])->assertOk()->assertJsonPath('answers', $answers)->assertJsonPath('quota_calls', 2 * $answers)
        ->assertJsonPath('markets.0.rules.0.sample_prompt_id', null)->assertJsonPath('markets.0.rules.0.next_due_on', null);
})->with([['daily', 180], ['weekly', 27], ['every_14_days', 14], ['every_21_days', 9], ['monthly', 6], ['off', 0]]);

test('copying a stored market plan checks current prices in preview and save', function () {
    planCatalogue();
    [$tenant, $owner] = planTenant();
    $world = planWorld($tenant, [planRule('chatgpt_api', 'daily')], []);
    EnginePrice::query()->where('engine_key', 'chatgpt_api')->delete();
    $draft = ['markets' => [['id' => $world['a']->id, 'plan_mode' => 'own']]];
    $this->actingAs($owner)->postJson(route('brands.plan.estimate', $world['brand']), $draft)
        ->assertUnprocessable()->assertJsonValidationErrors('markets.0.engines.0.engine_key');
    $this->putJson(route('brands.plan.update', $world['brand']), $draft)
        ->assertUnprocessable()->assertJsonValidationErrors('markets.0.engines.0.engine_key');
    expect(inTenant($tenant, fn () => $world['a']->fresh()->plan_mode))->toBe('inherit');
});

test('the pitch wizard quotes its fixed first-market rules even when the submitted engine list is empty', function () {
    planCatalogue();
    [$tenant, $owner] = planTenant(['max_prompts_per_market' => 25]);
    $tenant->forceFill(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)])->save();
    $this->actingAs($owner)->postJson(route('onboarding.cost-estimate'), [
        'standard' => ['engines' => []], 'prompt_count' => 2, 'core_prompt_count' => 1,
    ])->assertOk()->assertJsonPath('answers', 180)->assertJsonCount(3, 'engines');
});

test('brand totals accumulate sub-cent prices across markets before rounding and compare the precise budget', function () {
    planCatalogue();
    [$tenant, $owner] = planTenant(['monthly_budget_usd' => '0.00']);
    $rules = [planRule('chatgpt_api', 'monthly', 'core')];
    $world = planWorld($tenant, $rules, $rules);
    EnginePrice::query()->where('engine_key', 'chatgpt_api')->update(['price_per_call_usd' => '0.004']);
    $response = $this->actingAs($owner)->postJson(route('brands.plan.estimate', $world['brand']))
        ->assertOk()->assertJsonPath('markets.0.answers', 1)->assertJsonPath('markets.0.engine_cost_usd', '0.00')
        ->assertJsonPath('markets.1.engine_cost_usd', '0.00')->assertJsonPath('engine_cost_usd', '0.01');
    expect(array_column($response->json('issues'), 'code'))->toContain('exceeds_budget');
});

test('a priced new market with own choices can proceed alongside an unpriced saved market', function () {
    planCatalogue();
    [$tenant, $owner] = planTenant();
    $world = planWorld($tenant, [planRule('chatgpt_api', 'monthly')], []);
    EnginePrice::query()->where('engine_key', 'chatgpt_api')->delete();
    $new = [
        'plan_mode' => 'own', 'engines' => [planRule('gemini_api', 'monthly')],
        'prompt_count' => 5, 'core_prompt_count' => 2,
    ];
    $url = route('brands.plan.estimate', $world['brand']);
    $this->actingAs($owner)->postJson($url, ['new_market' => $new])->assertOk()
        ->assertJsonPath('total_cost_usd', null)->assertJsonPath('issues.0.market_id', $world['a']->id)
        ->assertJsonPath('markets.2.id', null)->assertJsonPath('markets.2.total_cost_usd', '0.05')
        ->assertJsonPath('markets.2.answers', 5);
    $new['plan_mode'] = 'inherit';
    $this->postJson($url, ['new_market' => $new])->assertUnprocessable()->assertJsonValidationErrors('new_market.engines');
});

test('stored rules use the current engine cap and weekly shared Sonnet baseline', function () {
    planCatalogue();
    config(['ekko.anthropic.monthly_pool_usd' => '100']);
    [$tenant, $owner] = planTenant();
    $daily = [planRule('chatgpt_api', 'daily', 'all', 3), planRule('claude_sonnet', 'daily', 'core')];
    $world = planWorld($tenant, $daily, []);
    $tenant->plan->update(['engine_max_frequencies' => ['chatgpt_api' => 'every_21_days'], 'max_repetitions' => 1]);
    $this->actingAs($owner)->postJson(route('brands.plan.estimate', $world['brand']))->assertOk()
        ->assertJsonPath('markets.0.rules.0.frequency', 'every_21_days')->assertJsonPath('markets.0.rules.0.repetitions', 1)
        ->assertJsonPath('markets.0.rules.0.answers', 3)
        ->assertJsonPath('markets.0.rules.1.frequency', 'weekly')->assertJsonPath('markets.0.rules.1.answers', 5);
});
