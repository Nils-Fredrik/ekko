<?php

// T43-F1, owner decision on Board #996, ADR 0034: wording actions (kind actions) is paid by the platform. It is
// booked in usage_events and on the Anthropic account, followed up in the admin overview and the forecast, but it
// does not count against the tenant's call quota or plan budget, raise quota alerts, stop runs or reach the invoice.

use App\AnthropicBudget\Account;
use App\AnthropicBudget\Estimator;
use App\AnthropicBudget\Money;
use App\AnthropicBudget\Planner;
use App\Billing\BillingStatement;
use App\Models\Alert;
use App\Models\Plan;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\UsageMonthly;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Usage\BillingAccount;
use App\Usage\MonthlyUsage;
use App\Usage\PlannedRun;
use App\Usage\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function inPlatformPaidTenant(Team $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], $callback));
}

function platformPaidEvent(string $kind, string $cost, int $calls = 1, ?CarbonImmutable $at = null): UsageEvent
{
    return UsageEvent::query()->create([
        'kind' => $kind, 'engine_key' => null, 'occurred_at' => $at ?? now('UTC'), 'calls' => $calls,
        'input_tokens' => 0, 'output_tokens' => 0, 'search_calls' => 0, 'cost_usd' => $cost, 'billed_to' => 'platform',
        'anthropic_operation' => $kind === 'actions' ? 'actions:'.fake()->uuid() : null,
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
    $this->plan = Plan::factory()->create(['monthly_call_quota' => 10, 'monthly_budget_usd' => '1.00']);
    $this->tenant = Team::factory()->tenant()->withPlan($this->plan)->create(['name' => 'Nordic']);
});

test('actions usage moves neither the call quota, the plan budget nor the quota alerts', function () {
    inPlatformPaidTenant($this->tenant, function () {
        UsageEvent::factory()->create(['calls' => 7, 'cost_usd' => '0.50']);
        platformPaidEvent('actions', '5.00', 40);
    });

    $this->artisan('ekko:usage-rollup')->assertSuccessful();

    inPlatformPaidTenant($this->tenant, function () {
        $usage = UsageMonthly::query()->sole();
        expect($usage->calls)->toBe(7)
            ->and($usage->cost_usd)->toBe('0.50000000')
            ->and($usage->status)->toBe('ok')
            ->and($usage->quota_alerted_threshold)->toBe(0)
            ->and(Alert::query()->count())->toBe(0)
            // Still booked as the tenant's usage event.
            ->and(UsageEvent::query()->where('kind', 'actions')->sum('calls'))->toBe(40);
    });
});

test('actions usage does not stop runs at admission', function () {
    $usage = inPlatformPaidTenant($this->tenant, function () {
        UsageEvent::factory()->create(['calls' => 9, 'cost_usd' => '0.10']);
        platformPaidEvent('actions', '3.00', 30);

        return app(MonthlyUsage::class)->forAdmission($this->tenant, CarbonImmutable::now('UTC'));
    });

    expect($usage->calls)->toBe(9)
        ->and(app(QuotaGuard::class)->allows($usage, new PlannedRun(1, true, BillingAccount::Platform, '0.01')))->toBeTrue();
});

test('the platform-paid kinds are configuration: without them actions count like any call', function () {
    config(['ekko.usage.platform_paid_kinds' => []]);
    inPlatformPaidTenant($this->tenant, function () {
        UsageEvent::factory()->create(['calls' => 7, 'cost_usd' => '0.10']);
        platformPaidEvent('actions', '0.01', 1);
    });

    $this->artisan('ekko:usage-rollup')->assertSuccessful();

    inPlatformPaidTenant($this->tenant, fn () => expect(UsageMonthly::query()->sole()->calls)->toBe(8)
        ->and(Alert::query()->sole()->payload['threshold_percent'])->toBe(80));
});

test('actions usage is on the Anthropic account and in the admin overview per tenant, not on the invoice', function () {
    config(['ekko.anthropic.monthly_pool_usd' => '100', 'ekko.anthropic.cycle_start_day' => 15]);
    $event = inPlatformPaidTenant($this->tenant, function () {
        UsageEvent::factory()->create(['calls' => 2, 'cost_usd' => '0.20']);

        return platformPaidEvent('actions', '0.05400000', 3);
    });
    $this->artisan('ekko:usage-rollup')->assertSuccessful();

    expect(DB::table('anthropic_credit_spend')->where('entry', $event->anthropic_operation)->value('cost_usd'))->toBe('0.05400000');

    $admin = User::factory()->platformAdmin()->withPersonalTeam()->withTwoFactor()->create();
    $this->actingAs($admin)->get(route('admin.usage'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Usage')
        ->where('tenants.0.name', 'Nordic')
        ->where('tenants.0.calls', 2)
        ->where('tenants.0.cost_usd', '0.20000000')
        ->where('tenants.0.platform_paid_calls', 3)
        ->where('tenants.0.platform_paid_cost_usd', '0.05400000')
        ->where('engines.1.engine_key', null)
        ->where('engines.1.cost_usd', '0.05400000'));

    $rows = app(BillingStatement::class)->rows($admin, '2026-11');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['calls'])->toBe(2)
        ->and($rows[0]['platform_cost_usd'])->toBe('0.20000000')
        ->and($rows[0]['unassigned_cost_usd'])->toBe('0.00000000');
});

test('the Anthropic forecast includes the expected platform-paid calls for the rest of the cycle', function () {
    config(['ekko.anthropic.monthly_pool_usd' => '100', 'ekko.anthropic.cycle_start_day' => 15]);
    inPlatformPaidTenant($this->tenant, function () {
        platformPaidEvent('actions', '0.70', 10, CarbonImmutable::now('UTC')->subDays(3));
        platformPaidEvent('actions', '0.70', 10, CarbonImmutable::now('UTC')->subDays(10));
        // Outside the 14-day window, and a kind the tenant pays for: neither is part of the estimate.
        platformPaidEvent('actions', '9.00', 10, CarbonImmutable::now('UTC')->subDays(15));
        platformPaidEvent('extraction', '9.00', 10, CarbonImmutable::now('UTC')->subDays(1));
    });
    $now = CarbonImmutable::now('UTC');
    $account = app(Account::class);
    $days = $account->cycle($now)->daysLeft($now);

    app(Planner::class)->plan($now);

    $expected = (int) ceil(Money::units('1.40') * $days / 14);
    $forecast = $account->summary()['forecast'];
    $spent = $account->spent($account->cycle($now));
    expect($forecast['platform_paid_usd'])->toBe(Money::usd($expected))
        ->and($forecast['forecast_usd'])->toBe(Money::usd($spent + $expected + intdiv($expected + 9, 10)));
});

test('factcheck usage is platform paid like actions: outside the quota, in the Anthropic forecast', function () {
    expect(config('ekko.usage.platform_paid_kinds'))->toContain('factcheck');

    $forecast = inPlatformPaidTenant($this->tenant, function () {
        UsageEvent::factory()->create(['calls' => 7, 'cost_usd' => '0.50']);
        platformPaidEvent('factcheck', '5.00', 40, CarbonImmutable::now('UTC')->subDays(3));

        return [
            app(MonthlyUsage::class)->forAdmission($this->tenant, CarbonImmutable::now('UTC'))->calls,
            app(Estimator::class)->platformPaid(CarbonImmutable::now('UTC'), 14),
        ];
    });

    expect($forecast[0])->toBe(7)->and($forecast[1])->toBeGreaterThan(0);
});
