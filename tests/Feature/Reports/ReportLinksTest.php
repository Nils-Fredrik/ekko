<?php

use App\Models\AggDaily;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\ReportLinkView;
use App\Models\ReportSchedule;
use App\Reports\DueReport;
use App\Reports\ReportPublisher;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Metrics\MetricsWorld;

test('shared report token is only returned once, scoped to one tenant and report, and views are recorded', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id]);
    $data = app(ReportPublisher::class)->viewData($world->tenant, $schedule, '2026-10-01', '2026-10-07', 'Private insight for this report');
    $data['trend'] = [['period' => '2026-10-03', 'visibility' => ['value' => 0.4, 'n' => 10, 'low_sample' => false]]];
    $report = Report::factory()->create(['report_schedule_id' => $schedule->id, 'web_snapshot' => $data]);
    $otherData = $data;
    $otherData['insight'] = 'Another report must remain private';
    $otherReport = Report::factory()->create(['report_schedule_id' => $schedule->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-07', 'web_snapshot' => $otherData]);

    $response = $this->actingAs($owner)->postJson(route('reports.links.store', $report), [])->assertCreated();
    $url = $response->json('url');
    expect($url)->toContain('#');
    [$endpoint, $token] = explode('#', $url, 2);
    expect($token)->toHaveLength(64);
    $id = $response->json('id');
    $this->deleteJson(route('reports.links.destroy', [$otherReport, $id]))->assertNotFound();
    app(TenantContext::class)->set($world->tenant->id);
    $link = ReportLink::query()->findOrFail($id);
    expect($link->token_hash)->toBe(hash('sha256', $token))->not->toBe($token);
    expect($link->expires_at->diffInDays(now(), absolute: true))->toBeGreaterThan(89);

    Auth::forgetGuards();
    $this->get($endpoint)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertDontSee($token);
    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertOk()->assertSee('Private insight for this report')
        ->assertDontSee('Another report must remain private')
        ->assertSee('shared-report-chart-data')->assertSee('2026-10-03')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
    $other = MetricsWorld::create();
    $this->withHeader('Authorization', 'Bearer '.$token)->get(route('reports.shared', ['tenant' => $other->tenant->id]))->assertNotFound();
    app(TenantContext::class)->set($world->tenant->id);
    expect(ReportLink::query()->findOrFail($id)->views)->toBe(1)
        ->and(ReportLinkView::query()->where('report_link_id', $id)->count())->toBe(1);

    $this->actingAs($owner)->deleteJson(route('reports.links.destroy', [$report, $id]))->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertNotFound();
    app(TenantContext::class)->set($world->tenant->id);
    expect(ReportLinkView::query()->where('report_link_id', $id)->count())->toBe(1);
});

test('expired and invalid report credentials receive 404 without increasing views', function () {
    $world = MetricsWorld::create();
    $report = Report::factory()->create();
    $token = bin2hex(random_bytes(32));
    $link = ReportLink::query()->create(['report_id' => $report->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->subSecond()]);
    $endpoint = route('reports.shared', ['tenant' => $world->tenant->id]);
    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertNotFound();
    $this->withHeader('Authorization', 'Bearer '.str_repeat('0', 64))->get($endpoint)->assertNotFound();
    app(TenantContext::class)->set($world->tenant->id);
    expect(ReportLinkView::query()->where('report_link_id', $link->id)->count())->toBe(0);
});

test('a link stops working when its tenant closes', function () {
    $world = MetricsWorld::create();
    $report = Report::factory()->create();
    $token = bin2hex(random_bytes(32));
    ReportLink::query()->create(['report_id' => $report->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    $world->tenant->forceFill(['status' => 'closed'])->save();
    $this->withHeader('Authorization', 'Bearer '.$token)->get(route('reports.shared', ['tenant' => $world->tenant->id]))->assertNotFound();
});

test('link lifetime is validated before a link is created', function () {
    $world = MetricsWorld::create();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $report = Report::factory()->create();
    $this->actingAs($owner)->postJson(route('reports.links.store', $report), ['days' => 0])->assertUnprocessable();
    $this->postJson(route('reports.links.store', $report), ['days' => 3651])->assertUnprocessable();
    app(TenantContext::class)->set($world->tenant->id);
    expect(ReportLink::query()->count())->toBe(0);
});

test('the published report retains its immutable web snapshot when recorded', function () {
    $world = MetricsWorld::create();
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id]);
    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'engine_key' => $world->engine->key, 'prompt_id' => $world->p1->id,
        'date' => '2026-10-03', 'responses' => 10, 'brand_mentioned' => 4,
    ]);
    $due = new DueReport($schedule->id, $world->brand->id, 'weekly', 'nb', ['report@example.test'], '2026-10-01', '2026-10-07', []);
    $snapshot = app(ReportPublisher::class)->viewData($world->tenant, $schedule, $due->from, $due->to, 'Recorded insight');
    $report = app(ReportPublisher::class)->record($due, 'reports/'.$world->tenant->id.'/report.pdf', $snapshot['actions'], $snapshot);
    expect($report->fresh()->web_snapshot['insight'])->toBe('Recorded insight')
        ->and($report->fresh()->web_snapshot['trend'][0]['period'])->toBe('2026-10-03')
        ->and($report->fresh()->web_snapshot['trend'][0]['visibility']['value'])->toBe(0.4)
        ->and($report->fresh()->web_snapshot['trend'][0]['visibility']['n'])->toBe(10);
});
