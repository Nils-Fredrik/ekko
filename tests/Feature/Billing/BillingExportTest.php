<?php

use App\Billing\BillingStatement;
use App\Models\Engine;
use App\Models\Plan;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function billingEvent(Team $tenant, ?Engine $engine, string $cost, string $at, string $account = 'platform', int $calls = 1): void
{
    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], fn () => UsageEvent::query()->create([
        'kind' => $engine === null ? 'extraction' : 'engine_call', 'engine_key' => $engine?->key,
        'cost_usd' => $cost, 'occurred_at' => $at, 'billed_to' => $account, 'calls' => $calls,
        'input_tokens' => 0, 'output_tokens' => 0, 'search_calls' => 0,
    ])));
}

function billingRows(string $csv): array
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream, escape: '');
    $rows = [];
    while (($row = fgetcsv($stream, escape: '')) !== false) {
        $rows[] = array_combine($headers, $row);
    }
    fclose($stream);

    return $rows;
}

test('monthly CSV reconciles exact usage, groups partner clients and excludes BYOK from invoices', function () {
    $admin = User::factory()->platformAdmin()->create();
    $partner = Team::factory()->tenant()->create(['name' => 'Synthetic partner']);
    $plan = Plan::factory()->create(['name' => 'Fixture plan']);
    $client = Team::factory()->tenant()->withPlan($plan)->create(['name' => 'Synthetic client', 'partner_team_id' => $partner->id, 'billing_markup_percent' => '25']);
    $other = Team::factory()->tenant()->withPlan($plan)->create(['name' => 'Another client', 'partner_team_id' => $partner->id, 'billing_markup_percent' => '0']);
    $direct = Team::factory()->tenant()->create(['name' => 'Direct client', 'billing_markup_percent' => '10']);
    $engine = Engine::factory()->create(['key' => 'chatgpt_api']);
    billingEvent($client, $engine, '10.10000001', '2026-10-01T00:00:00Z', calls: 2);
    billingEvent($client, $engine, '0.20000002', '2026-10-31T23:59:59Z');
    billingEvent($client, $engine, '90.00000000', '2026-10-15T12:00:00Z', 'byok', 3);
    billingEvent($client, null, '0.30000003', '2026-10-15T12:00:00Z');
    billingEvent($client, $engine, '1000', '2026-09-30T23:59:59Z');
    billingEvent($client, $engine, '1000', '2026-11-01T00:00:00Z');
    billingEvent($other, $engine, '2.00', '2026-10-20T00:00:00Z');
    billingEvent($direct, $engine, '3.00', '2026-10-20T00:00:00Z');
    $response = $this->actingAs($admin)->get('/admin/billing/export?month=2026-10')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $rows = billingRows($response->getContent());
    expect($rows)->toHaveCount(3)->and($rows[0]['account_id'])->toBe((string) $partner->id)
        ->and($rows[0]['tenant_id'])->toBe((string) $client->id)
        ->and($rows[0]['plan'])->toBe('Fixture plan')->and($rows[0]['calls'])->toBe('7')
        ->and($rows[0]['platform_cost_usd'])->toBe('10.60000006')->and($rows[0]['byok_cost_usd'])->toBe('90.00000000')
        ->and($rows[0]['cost_chatgpt_api_usd'])->toBe('10.30000003')->and($rows[0]['unassigned_cost_usd'])->toBe('0.30000003')
        ->and($rows[0]['invoice_total_usd'])->toBe('13.25')->and($rows[1]['invoice_total_usd'])->toBe('2.00')
        ->and($rows[2]['invoice_total_usd'])->toBe('3.30');
    $statement = app(BillingStatement::class);
    expect($statement->accounts($statement->rows($admin, '2026-10'))[0]['invoice_total_usd'])->toBe('15.25');
    expect(app(TenantContext::class)->id())->toBeNull();
});

test('missing markup is visible in preview and blocks CSV until an admin explicitly sets it', function () {
    $admin = User::factory()->platformAdmin()->create();
    $tenant = Team::factory()->tenant()->create();
    billingEvent($tenant, null, '1.00', '2026-10-20T00:00:00Z');
    $this->actingAs($admin)->get('/admin/billing?month=2026-10')->assertInertia(fn (Assert $page) => $page->component('Admin/Billing')->has('rows', 1)->where('rows.0.invoice_total_usd', null));
    $this->getJson('/admin/billing/export?month=2026-10')->assertUnprocessable()->assertJsonValidationErrors('month');
    $this->from('/admin/billing?month=2026-10')->put('/admin/billing/'.$tenant->id.'/markup', ['markup_percent' => '0'])->assertRedirect('/admin/billing?month=2026-10');
    $this->put('/admin/billing/'.$tenant->id.'/markup', ['markup_percent' => '0.0000'])->assertRedirect();
    expect($tenant->refresh()->billing_markup_percent)->toBe('0.0000')->and($tenant->toArray())->not->toHaveKey('billing_markup_percent');
    app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], fn () => expect(DB::table('activity_log')->where('event', 'billing.markup_changed')->count())->toBe(1)));
    $this->get('/admin/billing/export?month=2026-10')->assertOk();
});

test('exports escape spreadsheet formulas and retain CSV quotes and line breaks', function () {
    $tenant = Team::factory()->tenant()->create(['name' => "=SUM(1,2)\n\"quoted\"", 'billing_markup_percent' => '0']);
    billingEvent($tenant, null, '1', '2026-10-20T00:00:00Z');
    $csv = $this->actingAs(User::factory()->platformAdmin()->create())->get('/admin/billing/export?month=2026-10')->assertOk();
    expect(billingRows($csv->getContent())[0]['tenant_name'])->toBe("'=SUM(1,2)\n\"quoted\"");
});

test('billing rejects invalid months and markups without changing configuration', function () {
    $tenant = Team::factory()->tenant()->create(['billing_markup_percent' => '10']);
    $this->actingAs(User::factory()->platformAdmin()->create());
    foreach (['2026-13', '2026-1', '2026-10/../../', ''] as $month) {
        $this->getJson('/admin/billing/export?'.http_build_query(['month' => $month]))->assertUnprocessable();
    }
    foreach (['-1', '100000', '1.12345', 'invalid'] as $markup) {
        $this->putJson('/admin/billing/'.$tenant->id.'/markup', ['markup_percent' => $markup])->assertUnprocessable();
    }
    expect($tenant->refresh()->billing_markup_percent)->toBe('10.0000');
});

test('an ordinary tenant owner cannot view, export or change another tenant billing', function () {
    $tenant = Team::factory()->tenant()->create();
    $other = Team::factory()->tenant()->create(['billing_markup_percent' => '10']);
    $this->actingAs($tenant->owner)->getJson('/admin/billing?month=2026-10')->assertForbidden();
    $this->getJson('/admin/billing/export?month=2026-10')->assertForbidden();
    $this->putJson('/admin/billing/'.$other->id.'/markup', ['markup_percent' => '0'])->assertForbidden();
    expect($other->refresh()->billing_markup_percent)->toBe('10.0000');
});
