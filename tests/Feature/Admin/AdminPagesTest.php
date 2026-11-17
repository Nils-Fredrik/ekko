<?php

use App\Enums\TenantStatus;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\User;
use App\Tenancy\TenantContext;

it('lets a platform admin manage tenants, plans and engines', function () {
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->create();
    $plan = Plan::factory()->create(['name' => 'Basis']);
    $engine = Engine::factory()->create(['model_id' => 'old', 'is_enabled' => true]);

    $this->actingAs($admin)->post(route('admin.tenants.store'), [
        'name' => 'Nordic',
        'plan_id' => $plan->id,
    ])->assertRedirect();

    $tenant = Team::query()->where('name', 'Nordic')->firstOrFail();
    expect($tenant->plan_id)->toBe($plan->id)->and($tenant->personal_team)->toBeFalse();

    $this->put(route('admin.tenants.update', $tenant), [
        'name' => 'Nordic',
        'plan_id' => $plan->id,
        'status' => 'closed',
        'monthly_call_quota_override' => 12,
    ])->assertRedirect();

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Closed)
        ->and($tenant->closed_at)->not->toBeNull()
        ->and($tenant->monthly_call_quota_override)->toBe(12);

    $this->post(route('admin.plans.store'), [
        'name' => 'Byrå',
        'max_frequency' => 'daily',
        'max_repetitions' => 2,
        'allowed_engines' => [$engine->key],
        'engine_max_frequencies' => [$engine->key => 'weekly'],
    ])->assertRedirect();

    expect(Plan::query()->where('name', 'Byrå')->firstOrFail()->engine_max_frequencies)->toBe([$engine->key => 'weekly']);

    $this->put(route('admin.engines.update', $engine), [
        'model_id' => 'gpt-test',
        'is_enabled' => false,
    ])->assertRedirect();
    $future = now('UTC')->addDay()->format('Y-m-d H:i:s');
    $this->post(route('admin.engines.prices.store', $engine), [
        'valid_from' => now('UTC')->subDay()->format('Y-m-d H:i:s'),
        'price_per_call_usd' => '0.02',
        'price_per_1k_input_tokens_usd' => '0',
        'price_per_1k_output_tokens_usd' => '0',
        'price_per_search_usd' => '0',
    ])->assertSessionHasErrors('valid_from');
    $this->post(route('admin.engines.prices.store', $engine), [
        'valid_from' => $future,
        'price_per_call_usd' => '0.02',
        'price_per_1k_input_tokens_usd' => '0',
        'price_per_1k_output_tokens_usd' => '0',
        'price_per_search_usd' => '0',
    ])->assertRedirect();
    $this->post(route('admin.engines.prices.store', $engine), [
        'valid_from' => $future,
        'price_per_call_usd' => '9',
        'price_per_1k_input_tokens_usd' => '0',
        'price_per_1k_output_tokens_usd' => '0',
        'price_per_search_usd' => '0',
    ])->assertSessionHasErrors('valid_from');

    expect($engine->fresh()->is_enabled)->toBeFalse()
        ->and($engine->fresh()->model_id)->toBe('gpt-test')
        ->and(EnginePrice::query()->where('engine_key', $engine->key)->value('price_per_call_usd'))->toBe('0.02000000');

    app(TenantContext::class)->set($tenant->id);
    UsageEvent::withoutEvents(fn () => UsageEvent::query()->create([
        'occurred_at' => now('UTC'),
        'kind' => 'engine_call',
        'engine_key' => $engine->key,
        'calls' => 3,
        'cost_usd' => '1.25000000',
        'billed_to' => 'platform',
    ]));
    app(TenantContext::class)->forget();

    $this->get(route('admin.usage'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Usage')
        ->where('tenants.0.name', 'Nordic')
        ->where('engines.0.engine_key', $engine->key)
        ->where('engines.0.calls', 3));

    $this->actingAs(User::factory()->withPersonalTeam()->create());
    $this->get(route('admin.tenants.index'))->assertForbidden();
    $this->get(route('admin.plans.index'))->assertForbidden();
    $this->get(route('admin.engines.index'))->assertForbidden();
});

it('shows the price in effect and keeps quota skips out of the engine error rate', function () {
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->create();
    $tenant = Team::factory()->tenant()->create(['user_id' => $admin->id]);
    $engine = Engine::factory()->create();
    EnginePrice::factory()->for($engine)->create([
        'valid_from' => now('UTC')->subDay(),
        'price_per_call_usd' => '0.01000000',
    ]);
    EnginePrice::factory()->for($engine)->create([
        'valid_from' => now('UTC')->addDay(),
        'price_per_call_usd' => '0.99000000',
    ]);

    app(TenantContext::class)->set($tenant->id);
    $market = Market::factory()->create();
    $prompts = Prompt::factory()->for($market)->count(2)->create();
    $batch = RunBatch::factory()->for($market)->create();
    $finished = now();
    Run::factory()->for($batch, 'runBatch')->for($engine)->createMany([
        ['prompt_id' => $prompts[0]->id, 'repetition' => 1, 'status' => 'failed', 'finished_at' => $finished],
        ['prompt_id' => $prompts[0]->id, 'repetition' => 2, 'status' => 'collected', 'finished_at' => $finished],
        ['prompt_id' => $prompts[0]->id, 'repetition' => 3, 'status' => 'collected', 'finished_at' => $finished],
        ['prompt_id' => $prompts[1]->id, 'repetition' => 1, 'status' => 'collected', 'finished_at' => $finished],
        ['prompt_id' => $prompts[1]->id, 'repetition' => 2, 'status' => 'skipped_quota', 'finished_at' => $finished],
        ['prompt_id' => $prompts[1]->id, 'repetition' => 3, 'status' => 'skipped_quota', 'finished_at' => $finished],
    ]);
    app(TenantContext::class)->forget();

    $this->actingAs($admin)->get(route('admin.engines.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Engines')
        ->where('engines.0.key', $engine->key)
        ->where('engines.0.price.price_per_call_usd', '0.01000000')
        ->where('engines.0.scheduled_price.price_per_call_usd', '0.99000000')
        ->where('engines.0.runs', 4)
        ->where('engines.0.failed', 1)
        ->where('engines.0.unhealthy', true));
});
