<?php

use App\AnthropicBudget\Account;
use App\AnthropicBudget\Estimator;
use App\AnthropicBudget\Money;
use App\AnthropicBudget\Planner;
use App\Engines\Adapters\ClaudeHaikuAdapter;
use App\Engines\EngineCallFailed;
use App\Engines\EngineCollector;
use App\Engines\EngineRequest;
use App\Engines\RawResponseStore;
use App\Models\AnthropicBudgetPlan;
use App\Models\AnthropicInsightAllocation;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\User;
use App\Scheduling\Dispatcher;
use App\Scheduling\PromptSchedule;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Engines\EngineFixtures;

function inAnthropicTenant(Team $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], $callback));
}

beforeEach(function () {
    config(['ekko.anthropic.monthly_pool_usd' => '100', 'ekko.anthropic.cycle_start_day' => 15]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
});

test('the forecast stops scheduling a pitch at its expiry while retaining past spend', function () {
    $now = CarbonImmutable::parse('2026-11-20T12:00:00Z');
    $engine = Engine::factory()->create(['key' => 'claude_haiku', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => $now->subYear(), 'price_per_call_usd' => '0.02']);
    $plan = Plan::query()->where('is_pitch_plan', true)->sole();
    $tenant = Team::factory()->tenant()->withPlan($plan)->create([
        'kind' => 'pitch', 'pitch_expires_at' => $now->addDays(2), 'timezone' => 'UTC',
    ]);
    inAnthropicTenant($tenant, function () use ($engine) {
        $market = Market::factory()->create(['is_active' => true]);
        $market->brand->update(['status' => 'active']);
        MarketEngine::factory()->for($market)->for($engine)->create(['frequency' => 'weekly', 'repetitions' => 1]);
        Prompt::factory()->for($market)->create(['is_active' => true]);
    });
    $account = app(Account::class);
    $account->settleKnown('pitch-past-spend', '1.00000000');
    $estimator = app(Estimator::class);
    $baseline = 2 * ($estimator->engine('claude_haiku', $now) + $estimator->extraction(3000, true, 800));
    app(Planner::class)->plan($now);
    expect($account->summary()['forecast']['forecast_usd'])
        ->toBe(Money::usd(Money::units('1') + $baseline + intdiv($baseline + 9, 10)));

    $this->travelTo($now->addDays(2));
    app(Planner::class)->plan($now->addDays(2));
    expect($account->summary()['forecast']['forecast_usd'])->toBe('1.00000000');
});

test('account includes platform extraction and onboarding, excludes BYOK and survives tenant deletion', function () {
    $tenant = Team::factory()->tenant()->create();
    inAnthropicTenant($tenant, function () {
        UsageEvent::factory()->create(['kind' => 'extraction', 'engine_key' => null, 'cost_usd' => '90', 'billed_to' => 'platform', 'occurred_at' => now()]);
        UsageEvent::factory()->create(['kind' => 'onboarding', 'engine_key' => null, 'cost_usd' => '5', 'billed_to' => 'platform', 'occurred_at' => now()]);
        UsageEvent::factory()->create(['kind' => 'extraction', 'engine_key' => null, 'cost_usd' => '99', 'billed_to' => 'byok', 'occurred_at' => now()]);
        UsageEvent::factory()->create(['kind' => 'extraction', 'engine_key' => null, 'cost_usd' => '99', 'billed_to' => 'platform', 'occurred_at' => now()->subMonth()]);
    });
    $account = app(Account::class);
    expect($account->summary()['spent_usd'])->toBe('95.00000000')->and($account->summary()['reduced'])->toBeTrue();
    $tenant->delete();
    expect($account->summary()['spent_usd'])->toBe('95.00000000');
    $this->travelTo(CarbonImmutable::parse('2026-12-15'));
    expect($account->summary()['reduced'])->toBeFalse()->and($account->summary()['spent_usd'])->toBe('0.00000000');
});

test('pending admissions cannot share capacity and exhausted credit resets only in a new cycle', function () {
    $account = app(Account::class);
    expect($account->reserve('first', Money::units('60'), false))->toBeTrue()
        ->and($account->reserve('second', Money::units('50'), false))->toBeFalse();
    $account->settle('first');
    expect($account->reserve('second', Money::units('50'), false))->toBeTrue();
    $account->markExhausted();
    expect($account->reserve('core', 1, true))->toBeFalse()->and($account->summary()['exhausted'])->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2026-12-15'));
    expect($account->reserve('new-cycle', Money::units('100'), true))->toBeTrue()->and($account->summary()['exhausted'])->toBeFalse();
});

test('the provider credit error changes only the platform account and contains no provider body', function () {
    Engine::factory()->create(['key' => 'claude_haiku', 'provider' => 'anthropic']);
    config(['services.anthropic.key' => 'synthetic-key']);
    Http::preventStrayRequests()->fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.']], 400)]);
    expect(fn () => app(ClaudeHaikuAdapter::class)->collect(new EngineRequest('Synthetic prompt', 'NO', 'nb', null, '1', 'synthetic-byok')))->toThrow('anthropic_credits_exhausted');
    expect(app(Account::class)->summary()['exhausted'])->toBeFalse();
    expect(fn () => app(ClaudeHaikuAdapter::class)->collect(new EngineRequest('Synthetic prompt', 'NO', 'nb', null, '1')))->toThrow('anthropic_credits_exhausted');
    expect(app(Account::class)->summary()['exhausted'])->toBeTrue();
    Http::assertSentCount(2);
});

test('planning respects the selected Sonnet frequency and isolates idempotent tenant plans', function (string $frequency, bool $additional) {
    $engine = Engine::factory()->create(['key' => 'claude_sonnet', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subYear(), 'price_per_call_usd' => '0.02']);
    $plan = Plan::factory()->create(['allowed_engines' => ['claude_sonnet'], 'max_repetitions' => 3]);
    $tenant = Team::factory()->tenant()->withPlan($plan)->create();
    $prompt = inAnthropicTenant($tenant, function () use ($engine, $frequency) {
        $market = Market::factory()->create(['is_active' => true]);
        $market->brand->update(['status' => 'active']);
        MarketEngine::factory()->for($market)->for($engine)->create(['frequency' => $frequency, 'repetitions' => 1]);

        return Prompt::factory()->for($market)->create(['is_active' => true, 'priority' => 'core']);
    });
    while (PromptSchedule::due($prompt->id, 'weekly', CarbonImmutable::now()->setTimezone($tenant->timezone)) || PromptSchedule::due($prompt->id, 'monthly', CarbonImmutable::now()->setTimezone($tenant->timezone))) {
        $this->travel(1)->days();
    }
    expect(app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC')))->toBe($additional ? 1 : 0);
    expect(app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC')))->toBe(0);
    inAnthropicTenant($tenant, function () use ($additional) {
        expect(Run::query()->count())->toBe($additional ? 1 : 0)
            ->and(AnthropicBudgetPlan::query()->count())->toBe(1);
    });
    $other = Team::factory()->tenant()->create();
    inAnthropicTenant($other, fn () => expect(AnthropicBudgetPlan::query()->count())->toBe(0));
})->with([['daily', true], ['weekly', false], ['every_14_days', false], ['every_21_days', false], ['monthly', false]]);

test('admin usage is available only to the platform admin and has translated budget state', function () {
    $user = User::factory()->platformAdmin()->withPersonalTeam()->withTwoFactor()->create();
    $this->actingAs($user)->get(route('admin.usage'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Usage')->where('anthropic.pool_usd', '100.00000000'));
    $this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('admin.usage'))->assertForbidden();
});

test('reduced mode skips standard prompts before HTTP while a core Haiku run settles real usage', function () {
    $engine = Engine::factory()->create(['key' => 'claude_haiku', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subYear(), 'price_per_call_usd' => '0.02']);
    $tenant = Team::factory()->tenant()->withPlan(Plan::factory()->create(['allowed_engines' => ['claude_haiku']]))->create();
    [$core, $standard] = inAnthropicTenant($tenant, function () use ($engine) {
        UsageEvent::factory()->create(['kind' => 'extraction', 'engine_key' => null, 'cost_usd' => '95', 'billed_to' => 'platform', 'occurred_at' => now()]);
        $core = Run::factory()->for($engine)->create();
        $core->prompt->update(['priority' => 'core']);
        $standard = Run::factory()->for($engine)->create();
        $standard->prompt->update(['priority' => 'standard']);

        return [$core, $standard];
    });
    config(['services.anthropic.key' => 'synthetic-key']);
    Http::preventStrayRequests()->fake(['api.anthropic.com/*' => Http::response(EngineFixtures::load('claude', 'no-nb'))]);
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->expects('put')->once()->andReturn('gs://synthetic/account-test.json');
    app()->instance(RawResponseStore::class, $raw);
    app(TenantContext::class)->run($tenant->id, function () use ($core, $standard) {
        expect(app(EngineCollector::class)->collect($standard->id))->toBeNull();
        expect(app(EngineCollector::class)->collect($core->id))->not->toBeNull();
    });
    Http::assertSentCount(1);
    inAnthropicTenant($tenant, fn () => expect($standard->fresh()->status)->toBe('skipped_quota')->and($core->fresh()->status)->toBe('collected'));
    expect(DB::table('anthropic_budget_holds')->whereNull('settled_at')->count())->toBe(0)->and(app(Account::class)->summary()['spent_usd'])->toBe('95.02000000');
});

test('the real planner and shared account consume a full thirty-day allocation for twelve synthetic brands', function () {
    config(['ekko.anthropic.monthly_pool_usd' => '500']);
    $haiku = Engine::factory()->create(['key' => 'claude_haiku', 'provider' => 'anthropic', 'is_enabled' => true]);
    $sonnet = Engine::factory()->create(['key' => 'claude_sonnet', 'provider' => 'anthropic', 'is_enabled' => true]);
    foreach ([[$haiku, '0.02'], [$sonnet, '0.04']] as [$engine, $cost]) {
        EnginePrice::factory()->for($engine)->create(['valid_from' => '2026-01-01', 'price_per_call_usd' => $cost]);
    }
    $tenant = Team::factory()->tenant()->withPlan(Plan::factory()->create(['allowed_engines' => ['claude_haiku', 'claude_sonnet'], 'monthly_call_quota' => null]))->create(['timezone' => 'UTC']);
    $prompts = inAnthropicTenant($tenant, function () use ($haiku, $sonnet) {
        foreach (range(1, 12) as $brand) {
            $market = Market::factory()->create(['is_active' => true]);
            $market->brand->update(['status' => 'active']);
            MarketEngine::factory()->for($market)->for($haiku)->create(['frequency' => 'daily', 'repetitions' => 1]);
            MarketEngine::factory()->for($market)->for($sonnet)->create(['frequency' => 'daily', 'prompt_scope' => 'core', 'repetitions' => 1]);
            Prompt::factory()->for($market)->count(30)->create(['is_active' => true, 'priority' => 'core']);
        }

        return Prompt::query()->pluck('id')->all();
    });
    $account = app(Account::class);
    foreach (range(0, 29) as $day) {
        $now = CarbonImmutable::parse('2026-11-15T01:00:00Z')->addDays($day);
        $this->travelTo($now);
        app(Planner::class)->plan($now);
        inAnthropicTenant($tenant, function () use ($account, $prompts, $now, $day) {
            $extras = AnthropicBudgetPlan::query()->where('day', $now->toDateString())->sole()->additions;
            $sonnetCalls = count(array_filter($prompts, fn ($id) => PromptSchedule::due($id, 'weekly', $now))) + count(array_filter($extras, fn ($offer) => $offer['kind'] === 'sonnet_daily'));
            foreach ([['claude_haiku', 360, '0.02', true], ['claude_sonnet', $sonnetCalls, '0.04', false]] as [$engine, $calls, $price, $core]) {
                if ($calls === 0) {
                    continue;
                }
                $cost = Money::units($price) * $calls;
                $extraction = Money::units('0.00035') * $calls;
                $operation = 'simulation:'.$engine.':'.$day;
                if (! $account->reserve($operation, $cost + $extraction, $core)) {
                    continue;
                }
                UsageEvent::factory()->create(['kind' => 'engine_call', 'engine_key' => $engine, 'calls' => $calls, 'cost_usd' => Money::usd($cost), 'billed_to' => 'platform', 'occurred_at' => $now]);
                UsageEvent::factory()->create(['kind' => 'extraction', 'engine_key' => null, 'calls' => $calls, 'cost_usd' => Money::usd($extraction), 'billed_to' => 'platform', 'occurred_at' => $now]);
                $account->settle($operation);
            }
            foreach (AnthropicInsightAllocation::query()->whereNull('consumed_at')->get() as $insight) {
                UsageEvent::factory()->create(['kind' => 'pdf', 'engine_key' => 'claude_sonnet', 'cost_usd' => $insight->cost_usd, 'billed_to' => 'platform', 'occurred_at' => $now]);
                $insight->update(['consumed_at' => $now]);
                $account->settle('insight:'.$insight->brand_id.':'.$insight->cycle_start->toDateString());
            }
        });
        expect($account->spent($account->cycle()))->toBeLessThanOrEqual(Money::units('500'));
    }
    $used = $account->spent($account->cycle());
    expect($used)->toBeGreaterThanOrEqual(Money::units('450'))->toBeLessThanOrEqual(Money::units('500'));
});

test('a failed credit plan does not stop other providers from being dispatched', function () {
    config(['ekko.anthropic.monthly_pool_usd' => 'invalid-configuration']);
    $engine = Engine::factory()->create(['key' => 'chatgpt_api', 'provider' => 'openai', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subYear()]);
    $tenant = Team::factory()->tenant()->withPlan(Plan::factory()->create(['allowed_engines' => ['chatgpt_api']]))->create();
    inAnthropicTenant($tenant, function () use ($engine) {
        $market = Market::factory()->create(['is_active' => true]);
        $market->brand->update(['status' => 'active']);
        MarketEngine::factory()->for($market)->for($engine)->create(['frequency' => 'daily']);
        Prompt::factory()->for($market)->create(['is_active' => true]);
    });
    $dispatcher = app(Dispatcher::class);
    expect($dispatcher->dispatch(CarbonImmutable::now('UTC')))->toBe(1)->and($dispatcher->failures)->toBe(1);
});

test('known collection rejection releases capacity while unknown transport failure retains it', function (bool $known) {
    $engine = Engine::factory()->create(['key' => 'claude_haiku', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subYear(), 'price_per_call_usd' => '0.02']);
    $tenant = Team::factory()->tenant()->withPlan(Plan::factory()->create(['allowed_engines' => ['claude_haiku']]))->create();
    $run = inAnthropicTenant($tenant, function () use ($engine) {
        $run = Run::factory()->for($engine)->create();
        $run->prompt->update(['priority' => 'core']);

        return $run;
    });
    config(['services.anthropic.key' => 'synthetic-key']);
    Http::preventStrayRequests()->fake(['api.anthropic.com/*' => $known ? Http::response(['error' => ['type' => 'authentication_error']], 401) : Http::failedConnection()]);
    $raw = Mockery::mock(RawResponseStore::class);
    $raw->shouldNotReceive('put');
    app()->instance(RawResponseStore::class, $raw);
    try {
        app(TenantContext::class)->run($tenant->id, fn () => app(EngineCollector::class)->collect($run->id));
        $this->fail('A failed provider response must not succeed');
    } catch (EngineCallFailed $failure) {
        expect($failure->errorCode)->toBe($known ? 'anthropic_http_401' : 'anthropic_connection_failed');
    }
    expect(DB::table('anthropic_budget_holds')->whereNull('settled_at')->count())->toBe($known ? 0 : 1);
})->with([true, false]);

test('the credit forecast counts only scheduled core runs at the multiweek engine cap', function (string $frequency) {
    $now = CarbonImmutable::now('UTC');
    $engine = Engine::factory()->create(['key' => 'claude_haiku', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => $now->subYear(), 'price_per_call_usd' => '0.02']);
    $plan = Plan::factory()->create(['allowed_engines' => [$engine->key], 'engine_max_frequencies' => [$engine->key => $frequency]]);
    $tenant = Team::factory()->tenant()->withPlan($plan)->create(['timezone' => 'Pacific/Auckland']);
    $core = inAnthropicTenant($tenant, function () use ($engine) {
        $market = Market::factory()->create(['is_active' => true]);
        $market->brand->update(['status' => 'active']);
        MarketEngine::factory()->for($market)->for($engine)->create(['frequency' => 'daily', 'prompt_scope' => 'core']);
        Prompt::factory()->for($market)->create(['is_active' => true, 'priority' => 'standard']);

        return Prompt::factory()->for($market)->create(['is_active' => true, 'priority' => 'core']);
    });
    $account = app(Account::class);
    $days = $account->cycle($now)->daysLeft($now);
    $dueDays = 0;
    for ($offset = 0; $offset < $days; $offset++) {
        if (PromptSchedule::due($core->id, $frequency, $now->setTimezone($tenant->timezone)->addDays($offset))) {
            $dueDays++;
        }
    }
    $estimator = app(Estimator::class);
    $baseline = $dueDays * ($estimator->engine($engine->key, $now) + $estimator->extraction(3000, true, 800));
    app(Planner::class)->plan($now);
    expect($dueDays)->toBeGreaterThan(0)
        ->and($account->summary()['forecast']['forecast_usd'])->toBe(Money::usd($baseline + intdiv($baseline + 9, 10)));
})->with(['every_14_days', 'every_21_days']);

test('disabled Sonnet schedules receive no credit allocation', function (string $selected, string $maximum) {
    $engine = Engine::factory()->create(['key' => 'claude_sonnet', 'provider' => 'anthropic', 'is_enabled' => true]);
    EnginePrice::factory()->for($engine)->create(['valid_from' => now()->subYear()]);
    $plan = Plan::factory()->create(['allowed_engines' => [$engine->key], 'max_frequency' => $maximum]);
    $tenant = Team::factory()->tenant()->withPlan($plan)->create();
    inAnthropicTenant($tenant, function () use ($engine, $selected) {
        $market = Market::factory()->create(['is_active' => true]);
        $market->brand->update(['status' => 'active']);
        MarketEngine::factory()->for($market)->for($engine)->create(['frequency' => $selected]);
        Prompt::factory()->for($market)->create(['is_active' => true, 'priority' => 'core']);
    });

    expect(app(Dispatcher::class)->dispatch(CarbonImmutable::now('UTC')))->toBe(0);
    inAnthropicTenant($tenant, fn () => expect(AnthropicBudgetPlan::query()->sole()->additions)->toBe([])
        ->and(AnthropicInsightAllocation::query()->count())->toBe(0));
})->with([['off', 'daily'], ['daily', 'off']]);
