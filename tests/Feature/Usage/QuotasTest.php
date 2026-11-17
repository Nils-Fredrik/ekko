<?php

use App\Models\Alert;
use App\Models\Plan;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\UsageMonthly;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Usage\BillingAccount;
use App\Usage\PlannedRun;
use App\Usage\QuotaGuard;
use Database\Seeders\PlanSeeder;

function inQuotaTenant(Team $team, callable $callback): mixed
{
    return app(TenantContext::class)->run(
        $team->id,
        fn () => app(TenantRls::class)->run([$team->id], $callback),
    );
}

test('start plans have configurable limits and seeding preserves platform admin changes', function () {
    $this->seed(PlanSeeder::class);

    $basis = Plan::query()->where('name', 'Basis')->sole();
    $standard = Plan::query()->where('name', 'Standard')->sole();
    $agency = Plan::query()->where('name', 'Byrå')->sole();

    expect($basis->monthly_call_quota)->toBe(4000)
        ->and($basis->allowsEngine('claude_sonnet'))->toBeFalse()
        ->and($standard->monthly_call_quota)->toBe(50000)
        ->and($standard->maxFrequencyForEngine('claude_haiku'))->toBe('daily')
        ->and($standard->maxFrequencyForEngine('claude_sonnet'))->toBe('weekly')
        ->and($standard->allowsEngine('perplexity_api'))->toBeFalse()
        ->and($basis->allowsEngine('chatgpt_api'))->toBeTrue()
        ->and($basis->allowsEngine('gemini_api'))->toBeTrue()
        ->and($basis->allowsEngine('claude_haiku'))->toBeTrue()
        ->and($basis->allowsEngine('chatgpt_ui'))->toBeFalse()
        ->and($agency->monthly_call_quota)->toBeNull()
        ->and($agency->monthly_budget_usd)->toBeNull()
        ->and($agency->allowsEngine('chatgpt_api'))->toBeTrue();

    $standard->update(['monthly_call_quota' => 40000]);
    $this->seed(PlanSeeder::class);

    expect($standard->refresh()->monthly_call_quota)->toBe(40000);
});

test('call quota gives available capacity to core prompts when the full batch would overflow', function () {
    $guard = app(QuotaGuard::class);
    $usage = new UsageMonthly(['calls' => 6, 'quota_calls' => 10]);
    $runs = [
        new PlannedRun(1, false, BillingAccount::Platform, '0.01'),
        new PlannedRun(2, true, BillingAccount::Platform, '0.01'),
        new PlannedRun(3, true, BillingAccount::Byok, '0.05'),
        new PlannedRun(4, true, BillingAccount::Platform, '0.01'),
        new PlannedRun(5, true, BillingAccount::Platform, '0.01'),
        new PlannedRun(6, true, BillingAccount::Platform, '0.01'),
    ];

    expect($guard->allowedRunIds($usage, array_slice($runs, 0, 4)))->toBe([1, 2, 3, 4])
        ->and($guard->allowedRunIds($usage, $runs))->toBe([2, 3, 4, 5])
        ->and($guard->allowedRunIds($usage->fill(['calls' => 10]), $runs))->toBe([]);
});

test('a spending cap prioritizes core prompts for its billing account', function () {
    $guard = app(QuotaGuard::class);
    $usage = new UsageMonthly([
        'cost_usd' => '0.75',
        'budget_usd' => '1.00',
        'byok_cost_usd' => '12.00',
        'byok_budget_usd' => '15.00',
    ]);
    $runs = [
        new PlannedRun(1, false, BillingAccount::Platform, '0.30'),
        new PlannedRun(2, false, BillingAccount::Platform, '0.05'),
        new PlannedRun(3, true, BillingAccount::Platform, '0.25'),
        new PlannedRun(4, false, BillingAccount::Byok, '1.00'),
        new PlannedRun(5, true, BillingAccount::Platform, '0'),
    ];

    expect($guard->allowedRunIds($usage, $runs))->toBe([3, 4])
        ->and($guard->allowedRunIds($usage->fill(['cost_usd' => '1.00']), $runs))->toBe([4])
        ->and($guard->allowedRunIds($usage->fill(['byok_cost_usd' => '15.00']), $runs))->toBe([]);
});

test('hourly rollup emits each threshold once per UTC month and keeps tenant ledgers separate', function () {
    $this->travelTo(now()->parse('2026-11-15T12:00:00Z'));
    $plan = Plan::factory()->create(['monthly_call_quota' => 10, 'monthly_budget_usd' => '100']);
    $tenant = Team::factory()->tenant()->withPlan($plan)->create();
    $other = Team::factory()->tenant()->withPlan($plan)->create();

    inQuotaTenant($tenant, function () {
        UsageEvent::factory()->create(['occurred_at' => '2026-11-01T00:00:00Z', 'calls' => 8, 'cost_usd' => '1.00']);
        UsageEvent::factory()->create(['occurred_at' => '2026-10-31T23:59:59Z', 'calls' => 50, 'cost_usd' => '1000.00']);
    });
    inQuotaTenant($other, function () {
        UsageEvent::factory()->create(['occurred_at' => '2026-11-10T00:00:00Z', 'calls' => 1, 'cost_usd' => '3.00']);
    });

    $this->artisan('ekko:usage-rollup')->assertSuccessful();

    inQuotaTenant($tenant, function () {
        expect(UsageMonthly::query()->sole()->calls)->toBe(8)
            ->and(UsageMonthly::query()->sole()->status)->toBe('warning')
            ->and(Alert::query()->sole()->payload['threshold_percent'])->toBe(80);

        UsageEvent::factory()->create(['occurred_at' => now(), 'calls' => 2, 'cost_usd' => '0.0001']);
    });

    $this->artisan('ekko:usage-rollup')->assertSuccessful();
    $this->artisan('ekko:usage-rollup')->assertSuccessful();

    inQuotaTenant($tenant, function () {
        $usage = UsageMonthly::query()->sole();

        expect($usage->cost_usd)->toBe('1.00010000')
            ->and($usage->status)->toBe('capped')
            ->and(Alert::query()->orderBy('id')->get()->pluck('payload.threshold_percent')->all())->toBe([80, 100])
            ->and(Alert::query()->orderBy('id')->get()->pluck('payload.urgent')->all())->toBe([false, true])
            ->and(Alert::query()->firstOrFail()->payload['recipient_roles'])->toBe(['tenant_admin', 'platform_admin']);
    });

    inQuotaTenant($other, function () {
        expect(UsageMonthly::query()->sole()->cost_usd)->toBe('3.00000000')
            ->and(Alert::query()->count())->toBe(0);
    });

    $plan->update(['monthly_call_quota' => 20]);
    $this->travelTo(now()->parse('2026-12-01T00:00:00Z'));
    $this->artisan('ekko:usage-rollup')->assertSuccessful();

    inQuotaTenant($tenant, function () {
        $previous = UsageMonthly::query()->where('month', '2026-11-01')->sole();
        $current = UsageMonthly::query()->where('month', '2026-12-01')->sole();

        expect($previous->quota_calls)->toBe(10)
            ->and($current->quota_calls)->toBe(20)
            ->and($current->calls)->toBe(0)
            ->and($current->status)->toBe('ok');
    });
});

test('BYOK costs affect only the tenant budget while all calls count toward the tenant override', function () {
    $this->travelTo(now()->parse('2026-11-15T12:00:00Z'));
    $plan = Plan::factory()->create(['monthly_call_quota' => 10, 'monthly_budget_usd' => '10.00']);
    $tenant = Team::factory()->tenant()->withPlan($plan)->create([
        'byok_enabled' => true,
        'monthly_call_quota_override' => 50,
        'monthly_byok_budget_usd' => '20.00',
    ]);

    inQuotaTenant($tenant, function () {
        UsageEvent::factory()->create(['occurred_at' => now(), 'calls' => 8, 'cost_usd' => '2.00']);
        UsageEvent::factory()->create([
            'occurred_at' => now(),
            'billed_to' => BillingAccount::Byok->value,
            'calls' => 2,
            'cost_usd' => '16.00',
        ]);
    });

    $this->artisan('ekko:usage-rollup')->assertSuccessful();

    inQuotaTenant($tenant, function () {
        $usage = UsageMonthly::query()->sole();

        expect($usage->calls)->toBe(10)
            ->and($usage->quota_calls)->toBe(50)
            ->and($usage->cost_usd)->toBe('2.00000000')
            ->and($usage->byok_cost_usd)->toBe('16.00000000')
            ->and($usage->status)->toBe('warning')
            ->and(Alert::query()->sole()->payload['threshold_percent'])->toBe(80);
    });
});

test('an intentionally disabled platform budget does not alert when only a BYOK call is used', function () {
    $this->travelTo(now()->parse('2026-11-15T12:00:00Z'));
    $plan = Plan::factory()->create(['monthly_budget_usd' => '0.00']);
    $tenant = Team::factory()->tenant()->withPlan($plan)->create(['byok_enabled' => true]);

    inQuotaTenant($tenant, fn () => UsageEvent::factory()->create([
        'occurred_at' => now(),
        'billed_to' => BillingAccount::Byok->value,
        'calls' => 1,
        'cost_usd' => '1.00',
    ]));

    $this->artisan('ekko:usage-rollup')->assertSuccessful();

    inQuotaTenant($tenant, function () {
        $usage = UsageMonthly::query()->sole();

        expect($usage->status)->toBe('ok')
            ->and(Alert::query()->count())->toBe(0)
            ->and(app(QuotaGuard::class)->allows($usage, new PlannedRun(1, true, BillingAccount::Platform, '0')))->toBeFalse();
    });
});
