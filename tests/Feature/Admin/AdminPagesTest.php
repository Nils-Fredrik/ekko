<?php

use App\Enums\TenantStatus;
use App\Models\Engine;
use App\Models\EnginePrice;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;

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
    $this->post(route('admin.engines.prices.store', $engine), [
        'valid_from' => '2026-11-01 00:00:00',
        'price_per_call_usd' => '0.02',
        'price_per_1k_input_tokens_usd' => '0',
        'price_per_1k_output_tokens_usd' => '0',
        'price_per_search_usd' => '0',
    ])->assertRedirect();

    expect($engine->fresh()->is_enabled)->toBeFalse()
        ->and($engine->fresh()->model_id)->toBe('gpt-test')
        ->and(EnginePrice::query()->where('engine_key', $engine->key)->count())->toBe(1);

    $this->get(route('admin.usage'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Usage')
        ->where('tenants.0.name', 'Nordic'));

    $this->actingAs(User::factory()->withPersonalTeam()->create());
    $this->get(route('admin.tenants.index'))->assertForbidden();
    $this->get(route('admin.plans.index'))->assertForbidden();
    $this->get(route('admin.engines.index'))->assertForbidden();
});
