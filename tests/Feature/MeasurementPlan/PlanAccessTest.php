<?php

use App\MeasurementPlan\MeasurementPlans;
use App\Models\Brand;
use App\Models\BrandPlanEngine;
use App\Models\Market;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/MeasurementPlan/helpers.php';

// c22 (task:124): roles, tenant isolation, foreign brand and market IDs, and partly invalid changes that write nothing.
// The route register (tests/Feature/TenantIsolation/routes.php) also covers guests, other tenants' brands and roles.

beforeEach(function () {
    Http::fake();
    planCatalogue();
});

/** Everything the plan can change, for checking that a refused request wrote nothing. */
function planState(Team $tenant): array
{
    return inTenant($tenant, fn () => [
        DB::table('brand_plan_engines')->orderBy('id')->get()->toArray(),
        DB::table('market_engines')->orderBy('id')->get()->toArray(),
        DB::table('markets')->orderBy('id')->get(['id', 'plan_mode'])->toArray(),
    ]);
}

it('lets every role read the plan and only admins change it', function (string $role, bool $may) {
    [$tenant] = planTenant();
    $w = planWorld($tenant, [planRule('chatgpt_api', 'daily')], []);
    $user = User::factory()->withPersonalTeam()->withTwoFactor()->create();
    $tenant->users()->attach($user, ['role' => $role]);
    $user->switchTeam($tenant);
    $before = planState($tenant);

    $this->actingAs($user)->getJson(route('brands.plan.show', $w['brand']))->assertOk()->assertJsonPath('standard', [planRule('chatgpt_api', 'daily')]);
    $response = $this->putJson(route('brands.plan.update', $w['brand']), ['standard' => ['engines' => [planRule('chatgpt_api', 'weekly')]]]);

    if ($may) {
        $response->assertOk();
    } else {
        $response->assertForbidden();
        expect(planState($tenant))->toEqual($before);
    }
})->with([['admin', true], ['partner_advisor', true], ['editor', false], ['reader', false]]);

it('hides another tenant\'s brand and refuses its market IDs on an own brand without writing', function () {
    [$tenant, $owner] = planTenant();
    [$other] = planTenant();
    $mine = planWorld($tenant, [planRule('chatgpt_api', 'daily')], [planRule('claude_haiku', 'daily')]);
    $theirs = planWorld($other, [planRule('chatgpt_api', 'daily')], [planRule('claude_haiku', 'daily')]);
    $before = [planState($tenant), planState($other)];
    $this->actingAs($owner);

    $this->getJson(route('brands.plan.show', $theirs['brand']))->assertNotFound();
    $this->putJson(route('brands.plan.update', $theirs['brand']), ['standard' => ['engines' => []]])->assertNotFound();
    $this->putJson(route('brands.plan.update', $mine['brand']), [
        'standard' => ['engines' => [planRule('chatgpt_api', 'weekly')]],
        'markets' => [['id' => $theirs['b']->id, 'plan_mode' => 'inherit']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['markets.0.id' => __('app.workspace.unknown_market')]);

    expect([planState($tenant), planState($other)])->toEqual($before);
});

it('refuses a market of another brand in the same tenant', function () {
    [$tenant, $owner] = planTenant();
    $first = planWorld($tenant, [planRule('chatgpt_api', 'daily')], []);
    $second = planWorld($tenant, [planRule('chatgpt_api', 'weekly')], []);
    $before = planState($tenant);

    $this->actingAs($owner)->putJson(route('brands.plan.update', $first['brand']), ['markets' => [['id' => $second['a']->id, 'plan_mode' => 'own', 'engines' => []]]])
        ->assertUnprocessable()->assertJsonValidationErrors('markets.0.id');
    expect(planState($tenant))->toEqual($before);
});

it('writes nothing when any part of a change is invalid', function (array $payload, array $errors) {
    [$tenant, $owner] = planTenant(['allowed_engines' => ['chatgpt_api', 'claude_haiku']]);
    $w = planWorld($tenant, [planRule('chatgpt_api', 'daily')], [planRule('claude_haiku', 'daily')]);
    $payload = json_decode(str_replace(['"@a"', '"@b"'], [(string) $w['a']->id, (string) $w['b']->id], json_encode($payload)), true);
    $before = planState($tenant);

    $this->actingAs($owner)->putJson(route('brands.plan.update', $w['brand']), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($errors);
    expect(planState($tenant))->toEqual($before);
})->with([
    'valid standard, market rule outside the package' => [
        ['standard' => ['engines' => [['engine_key' => 'chatgpt_api', 'prompt_scope' => 'all', 'frequency' => 'weekly', 'repetitions' => 1]]],
            'markets' => [['id' => '@b', 'plan_mode' => 'own', 'engines' => [['engine_key' => 'claude_sonnet', 'prompt_scope' => 'core', 'frequency' => 'weekly', 'repetitions' => 1]]]]],
        ['markets.0.engines.0.engine_key'],
    ],
    'valid market, invalid standard' => [
        ['standard' => ['engines' => [['engine_key' => 'chatgpt_api', 'prompt_scope' => 'all', 'frequency' => 'daily', 'repetitions' => 4]]],
            'markets' => [['id' => '@a', 'plan_mode' => 'own', 'engines' => []]]],
        ['standard.engines.0.repetitions'],
    ],
    'inherit with engines' => [
        ['markets' => [['id' => '@b', 'plan_mode' => 'inherit', 'engines' => []]]],
        ['markets.0.engines'],
    ],
    'the same market twice' => [
        ['markets' => [['id' => '@a', 'plan_mode' => 'own'], ['id' => '@a', 'plan_mode' => 'inherit']]],
        ['markets.1.id'],
    ],
    'unknown mode' => [
        ['markets' => [['id' => '@a', 'plan_mode' => 'borrowed']]],
        ['markets.0.plan_mode'],
    ],
]);

it('keeps the plan tables behind row-level security and same-tenant foreign keys', function () {
    [$tenant] = planTenant();
    [$other] = planTenant();
    $mine = planWorld($tenant, [planRule('chatgpt_api', 'daily')], []);
    planWorld($other, [planRule('claude_haiku', 'daily')], []);

    // Raw SQL under the tenant's RLS sees only its own rows.
    $visible = inTenant($tenant, fn () => DB::table('brand_plan_engines')->pluck('tenant_id')->unique()->all());
    expect($visible)->toBe([$tenant->id]);

    // A row in another tenant that points to this tenant's brand is refused by the composite foreign key.
    app(TenantContext::class)->run($other->id, function () use ($mine): void {
        expect(BrandPlanEngine::query()->count())->toBe(1);
        expect(fn () => DB::transaction(fn () => BrandPlanEngine::query()->create(['brand_id' => $mine['brand']->id] + planRule('chatgpt_api', 'daily'))))
            ->toThrow(QueryException::class, 'brand_plan_engines_tenant_id_brand_id_foreign');
    });

    // The service only sees the tenant's own markets.
    inTenant($other, function () use ($other, $mine): void {
        $foreignBrand = new Brand;
        $foreignBrand->forceFill(['id' => $mine['brand']->id]);
        expect(fn () => app(MeasurementPlans::class)->draft($other, $foreignBrand, ['markets' => [['id' => $mine['a']->id, 'plan_mode' => 'inherit']]]))
            ->toThrow(ValidationException::class);
        expect(Market::query()->whereKey($mine['a']->id)->exists())->toBeFalse();
    });
});
