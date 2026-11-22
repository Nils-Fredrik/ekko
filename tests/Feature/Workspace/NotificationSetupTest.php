<?php

use App\Alerts\AlertRules;
use App\Alerts\AlertType;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\Report;
use App\Models\ReportSchedule;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Metrics\MetricsWorld;

test('opening notifications does not create rules, and each field saves on its own', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $this->actingAs($owner);
    $other = Brand::factory()->create();
    $own = ReportSchedule::factory()->for($world->brand)->create(['cadence' => 'weekly', 'recipients' => ['owner@example.test']]);
    ReportSchedule::factory()->for($other)->create(['recipients' => ['other@example.test']]);

    expect(AlertRule::query()->count())->toBe(0);
    $this->get(route('brands.setup.notifications', $world->brand))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Brands/Setup/Notifications')
            ->has('rules', 7)
            ->where('rules.0.type', 'visibility_drop')
            ->where('rules.0.unit', 'points')
            ->where('tenantName', $world->tenant->name)
            ->has('reportLocales')
            ->has('schedules', 1)
            ->where('schedules.0.id', $own->id));
    expect(AlertRule::query()->count())->toBe(0);

    $this->put(route('brands.alert-rules.upsert', [$world->brand, 'visibility_drop']), ['threshold' => 12])
        ->assertRedirect();
    $rule = AlertRule::query()->sole();
    expect($rule->brand_id)->toBe($world->brand->id)
        ->and($rule->is_active)->toBeTrue()
        ->and((float) $rule->threshold)->toBe(12.0);

    $this->put(route('brands.alert-rules.upsert', [$world->brand, 'visibility_drop']), ['is_active' => false])
        ->assertRedirect();
    expect(AlertRule::query()->count())->toBe(1)
        ->and($rule->fresh()->is_active)->toBeFalse()
        ->and((float) $rule->fresh()->threshold)->toBe(12.0);
});

test('the first edit of a legacy integration rule changes the rule the page shows', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $this->actingAs($owner);
    $legacy = AlertRule::factory()->for($world->brand)->create([
        'type' => 'integration_error',
        'is_active' => true,
        'channels' => ['in_app'],
    ]);

    $this->put(route('brands.alert-rules.upsert', [$world->brand, 'integration_error']), ['is_active' => false])
        ->assertRedirect();

    $shown = app(AlertRules::class)->forSource(AlertType::IntegrationError, $world->brand->id);
    expect($shown->id)->toBe($legacy->id)->and($shown->is_active)->toBeFalse();
});

test('report history keeps reports after the plan is deleted', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $this->actingAs($owner);
    $schedule = ReportSchedule::factory()->for($world->brand)->create();
    $report = Report::factory()->create(['report_schedule_id' => $schedule->id]);
    $schedule->delete();

    $this->get(route('brands.setup.notifications', $world->brand))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('schedules', 0)
            ->where('reports.0.id', $report->id));
});

test('the brand page carries the health header and the checklist can be hidden', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $this->actingAs($owner);

    $this->get(route('brands.show', $world->brand))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('health.checklist', 6)
            ->where('health.checklist.0.key', 'plan')
            ->where('health.checklist.5.optional', true)
            ->where('health.checklist_hidden', false)
            ->has('health.markets', 1));

    $this->post(route('brands.checklist.hide', $world->brand))->assertRedirect();
    $this->get(route('brands.show', $world->brand))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('health.checklist_hidden', true));
});

test('the inbox all-count matches the sidebar count for the same brand', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $this->actingAs($owner);
    $rule = AlertRule::factory()->create(['type' => 'quota', 'brand_id' => null, 'channels' => ['in_app']]);
    Alert::factory()->create([
        'alert_rule_id' => $rule->id,
        'payload' => ['threshold_percent' => 80, 'month' => now()->format('Y-m')],
    ]);

    $this->get(route('inbox.index', ['brand' => $world->brand->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.all', 1)
            ->where('counts.alert', 1)
            ->where('items.0.title', __('app.alert_types.quota'))
            ->missing('items.0.subject')
            ->loadDeferredProps('workspace', fn (AssertableInertia $deferred) => $deferred
                ->where('workspace.counts.inbox', 1)
                ->where('workspace.brand.id', $world->brand->id)));
});
