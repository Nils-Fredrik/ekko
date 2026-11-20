<?php

use App\Models\Action;
use App\Models\AggDaily;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\ReportLinkView;
use App\Models\ReportSchedule;
use App\Reports\DueReport;
use App\Reports\ReportPublisher;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    $otherUrl = $this->postJson(route('reports.links.store', $otherReport), [])->assertCreated()->json('url');
    [$otherEndpoint] = explode('#', $otherUrl, 2);
    $this->deleteJson(route('reports.links.destroy', [$otherReport, $id]))->assertNotFound();
    app(TenantContext::class)->set($world->tenant->id);
    $link = ReportLink::query()->findOrFail($id);
    expect($link->token_hash)->toBe(hash('sha256', $token))->not->toBe($token);
    expect($link->expires_at->diffInDays(now(), absolute: true))->toBeGreaterThan(89);

    Auth::forgetGuards();
    $this->withHeader('Accept-Language', 'nb')->get($endpoint)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertDontSee($token)
        ->assertSee('<title>Rapport</title>', false)
        ->assertHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertOk()->assertSee('Private insight for this report')
        ->assertDontSee('Another report must remain private')
        ->assertSee('shared-report-chart-data')->assertSee('2026-10-03')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
    $this->withHeader('Authorization', 'Bearer '.$token)->get($otherEndpoint)->assertNotFound();
    $other = MetricsWorld::create();
    $this->withHeader('Authorization', 'Bearer '.$token)->get(route('reports.shared', ['tenant' => $other->tenant->id, 'selector' => $link->public_id]))->assertNotFound();
    app(TenantContext::class)->set($world->tenant->id);
    expect(ReportLink::query()->findOrFail($id)->views)->toBe(1)
        ->and(ReportLinkView::query()->where('report_link_id', $id)->count())->toBe(1);

    $this->actingAs($owner)->deleteJson(route('reports.links.destroy', [$report, $id]))->assertOk();
    $this->flushHeaders()->withHeader('Accept-Language', 'nb')->get($endpoint)->assertNotFound()->assertSee('Rapporten finnes ikke eller lenken er utløpt.', false);
    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertNotFound();
    app(TenantContext::class)->set($world->tenant->id);
    expect(ReportLinkView::query()->where('report_link_id', $id)->count())->toBe(1);
});

test('expired and invalid report credentials receive 404 without increasing views', function () {
    $world = MetricsWorld::create();
    $report = Report::factory()->create();
    $token = bin2hex(random_bytes(32));
    $link = ReportLink::query()->create(['report_id' => $report->id, 'public_id' => bin2hex(random_bytes(16)), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->subSecond()]);
    $endpoint = route('reports.shared', ['tenant' => $world->tenant->id, 'selector' => $link->public_id]);
    $this->get($endpoint)->assertNotFound();
    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertNotFound();
    $this->withHeader('Authorization', 'Bearer '.str_repeat('0', 64))->get($endpoint)->assertNotFound();
    app(TenantContext::class)->set($world->tenant->id);
    expect(ReportLinkView::query()->where('report_link_id', $link->id)->count())->toBe(0);
});

test('a link stops working when its tenant closes', function () {
    $world = MetricsWorld::create();
    $report = Report::factory()->create();
    $token = bin2hex(random_bytes(32));
    $link = ReportLink::query()->create(['report_id' => $report->id, 'public_id' => bin2hex(random_bytes(16)), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    $world->tenant->forceFill(['status' => 'closed'])->save();
    $endpoint = route('reports.shared', ['tenant' => $world->tenant->id, 'selector' => $link->public_id]);
    $this->get($endpoint)->assertNotFound();
    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertNotFound();
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

test('the PDF renders the published trend and preserves gaps', function () {
    $world = MetricsWorld::create();
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id]);
    $data = app(ReportPublisher::class)->viewData($world->tenant, $schedule, '2026-10-01', '2026-10-03', null);
    $data['trend'] = [
        ['period' => '2026-10-01', 'visibility' => ['value' => 0.4, 'n' => 4, 'low_sample' => true]],
        ['period' => '2026-10-03', 'visibility' => ['value' => 0.6, 'n' => 20, 'low_sample' => false]],
    ];
    app()->setLocale('en');
    $html = view('reports.pdf', $data)->render();
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//*[@data-testid="pdf-trend-values"]/tbody/tr');
    expect($rows->length)->toBe(2)
        ->and(trim($rows->item(0)->textContent))->toContain('2026-10-01', '40%', '4', 'Small sample')
        ->and($rows->item(0)->getAttribute('class'))->toContain('low')
        ->and(trim($rows->item(1)->textContent))->toContain('2026-10-03', '60%', '20')
        ->and($rows->item(1)->getAttribute('class'))->not->toContain('low');
    expect($html)->toContain('data-testid="pdf-report-trend"')
        ->toContain('2026-10-01 · 40%')
        ->toContain('2026-10-03 · 60%')
        ->not->toContain('stroke="#b62b1b" stroke-width="2"');
    expect(view('reports.pdf', [...$data, 'shared' => true])->render())
        ->toContain('data-testid="pdf-report-trend"')
        ->toContain('shared-report-chart-data')
        ->toContain('id="report-cover"')
        ->toContain('id="report-development"');

    $data['trend'][] = ['period' => '2026-10-02', 'visibility' => ['value' => 0.5, 'n' => 30, 'low_sample' => false]];
    expect(view('reports.pdf', $data)->render())->toContain('stroke="#b62b1b" stroke-width="2"');
});

test('a shared report keeps its publication language after the schedule language changes', function () {
    $world = MetricsWorld::create();
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id, 'locale' => 'en']);
    $due = new DueReport($schedule->id, $world->brand->id, 'weekly', 'en', ['report@example.test'], '2026-10-01', '2026-10-07', []);
    $publisher = app(ReportPublisher::class);
    $document = $publisher->document($world->tenant, $due, 'Published insight');
    $document['data']['trend'] = [['period' => '2026-10-03', 'visibility' => ['value' => 0.4, 'n' => 10, 'low_sample' => false]]];
    $report = $publisher->record($due, $document['path'], $document['data']['actions'], $document['data']);
    expect($report->fresh()->web_snapshot['locale'])->toBe('en')
        ->and($report->fresh()->web_snapshot['headlines'][0]['label'])->toBe('Visibility');

    $token = bin2hex(random_bytes(32));
    $link = ReportLink::query()->create(['report_id' => $report->id, 'public_id' => bin2hex(random_bytes(16)), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    $endpoint = route('reports.shared', ['tenant' => $world->tenant->id, 'selector' => $link->public_id]);
    $schedule->update(['locale' => 'nb']);
    app()->setLocale('nb');
    $this->withHeader('Accept-Language', 'nb')->get($endpoint)->assertOk()->assertSee('<title>Rapport</title>', false);
    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertOk()
        ->assertSee('<html lang="en"', false)
        ->assertSee('Key figures')
        ->assertSee('Visibility over time')
        ->assertSee('Published insight')
        ->assertDontSee('Nøkkeltall');

    // Existing snapshots without a publication locale remain readable in the current schedule language.
    app(TenantContext::class)->set($world->tenant->id);
    $legacy = $report->fresh()->web_snapshot;
    unset($legacy['locale']);
    $report->forceFill(['web_snapshot' => $legacy])->save();
    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertOk()
        ->assertSee('<html lang="nb"', false)
        ->assertSee('Nøkkeltall')
        ->assertSee('Synlighet over tid');

    // Reports without a snapshot are reconstructed in the schedule's current language.
    app(TenantContext::class)->set($world->tenant->id);
    $report->forceFill(['web_snapshot' => null])->save();
    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertOk()
        ->assertSee('<html lang="nb"', false)
        ->assertSee('Denne nettversjonen er rekonstruert');
});

test('the report uses the queued publication language if its schedule changes during generation', function () {
    $world = MetricsWorld::create();
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id, 'locale' => 'en']);
    $due = new DueReport($schedule->id, $world->brand->id, 'weekly', 'en', ['report@example.test'], '2026-10-01', '2026-10-07', []);
    $schedule->update(['locale' => 'nb']);
    app()->setLocale('nb');

    $document = app(ReportPublisher::class)->document($world->tenant, $due, null);
    expect($document['data']['locale'])->toBe('en')
        ->and($document['data']['headlines'][0]['label'])->toBe('Visibility')
        ->and(app()->getLocale())->toBe('nb');
});
test('the initial shared page and 404 use the recipient browser language without exposing report details', function (string $acceptLanguage, string $locale, string $title, string $loading, string $notFound) {
    $world = MetricsWorld::create();
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id, 'locale' => 'en']);
    $due = new DueReport($schedule->id, $world->brand->id, 'weekly', 'en', ['report@example.test'], '2026-10-01', '2026-10-07', []);
    $publisher = app(ReportPublisher::class);
    $document = $publisher->document($world->tenant, $due, 'Private insight');
    $report = $publisher->record($due, $document['path'], $document['data']['actions'], $document['data']);
    $token = bin2hex(random_bytes(32));
    $link = ReportLink::query()->create(['report_id' => $report->id, 'public_id' => bin2hex(random_bytes(16)), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    $endpoint = route('reports.shared', ['tenant' => $world->tenant->id, 'selector' => $link->public_id]);
    $csp = "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'";

    app()->setLocale('nb');
    $this->withHeader('Accept-Language', $acceptLanguage)->get($endpoint)->assertOk()
        ->assertHeader('Content-Language', $locale)->assertHeader('Content-Security-Policy', $csp)
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('<html lang="'.$locale.'"', false)->assertSee('<title>'.$title.'</title>', false)
        ->assertSee($loading)->assertDontSee('Private insight')->assertDontSee($token);
    expect(app()->getLocale())->toBe('nb');

    // Once the bearer token is supplied the report retains its publication language.
    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertOk()
        ->assertHeader('Content-Language', 'en')->assertSee('<html lang="en"', false)->assertSee('Private insight');
    $this->flushHeaders()->withHeader('Accept-Language', $acceptLanguage);
    $badTenant = route('reports.shared', ['tenant' => 'invalid', 'selector' => $link->public_id]);
    $badSelector = route('reports.shared', ['tenant' => $world->tenant->id, 'selector' => 'invalid']);
    foreach ([$badTenant, $badSelector] as $url) {
        $this->get($url)->assertNotFound()->assertHeader('Content-Language', $locale)
            ->assertHeader('Content-Security-Policy', $csp)->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertSee($notFound)->assertDontSee('Private insight');
    }
    $this->withHeader('Authorization', 'Bearer '.str_repeat('0', 64))->get($endpoint)->assertNotFound()
        ->assertHeader('Content-Language', $locale)->assertHeader('Content-Security-Policy', $csp)->assertSee($notFound);

    app(TenantContext::class)->set($world->tenant->id);
    $link->forceFill(['revoked_at' => now()])->save();
    $this->flushHeaders()->withHeader('Accept-Language', $acceptLanguage)->get($endpoint)->assertNotFound()
        ->assertHeader('Content-Language', $locale)->assertHeader('Content-Security-Policy', $csp)
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('<html lang="'.$locale.'"', false)->assertSee('<title>'.$title.'</title>', false)
        ->assertSee($notFound)->assertDontSee('Private insight');
})->with([
    ['nb-NO,nb;q=0.9', 'nb', 'Rapport', 'Laster rapport', 'Rapporten finnes ikke eller lenken er utløpt.'],
    ['sv-SE,sv;q=0.9', 'sv', 'Rapport', 'Laddar rapport', 'Rapporten finns inte eller länken har gått ut.'],
    ['en-US,en;q=0.9', 'en', 'Report', 'Loading report', 'The report does not exist or the link has expired.'],
]);

test('shared report rate limit responses retain security headers', function () {
    $url = route('reports.shared', ['tenant' => 'invalid', 'selector' => 'invalid']);
    for ($i = 0; $i < 60; $i++) {
        $this->get($url)->assertNotFound();
    }
    $this->get($url)->assertStatus(429)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
});

test('report data is never read before a valid token for an active link', function () {
    $world = MetricsWorld::create();
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id, 'locale' => 'en']);
    $due = new DueReport($schedule->id, $world->brand->id, 'weekly', 'en', ['report@example.test'], '2026-10-01', '2026-10-07', []);
    $publisher = app(ReportPublisher::class);
    $document = $publisher->document($world->tenant, $due, 'Private insight');
    $report = $publisher->record($due, $document['path'], $document['data']['actions'], $document['data']);
    $token = bin2hex(random_bytes(32));
    $link = ReportLink::query()->create(['report_id' => $report->id, 'public_id' => bin2hex(random_bytes(16)), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    $endpoint = route('reports.shared', ['tenant' => $world->tenant->id, 'selector' => $link->public_id]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $reportReads = function () use (&$queries): array {
        return array_values(array_filter($queries, fn (string $sql) => preg_match('/\b(?:from|join)\s+"?reports"?(?:\s|$)/i', $sql) === 1));
    };

    $this->withHeader('Accept-Language', 'sv')->get($endpoint)->assertOk()->assertSee('<html lang="sv"', false)->assertDontSee('Private insight');
    expect($reportReads())->toBe([]);
    $this->withHeader('Authorization', 'Bearer '.str_repeat('0', 64))->get($endpoint)->assertNotFound()->assertSee('<html lang="sv"', false);
    expect($reportReads())->toBe([]);

    $this->withHeader('Authorization', 'Bearer '.$token)->get($endpoint)->assertOk()->assertSee('Private insight');
    expect($reportReads())->not->toBeEmpty();
    app(TenantContext::class)->set($world->tenant->id);
    $link->forceFill(['revoked_at' => now()])->save();
    $queries = [];
    $this->flushHeaders()->withHeader('Accept-Language', 'sv')->get($endpoint)->assertNotFound()->assertSee('<html lang="sv"', false);
    expect($reportReads())->toBe([]);
    app(TenantContext::class)->set($world->tenant->id);
    $link->forceFill(['revoked_at' => null, 'expires_at' => now()->subSecond()])->save();
    $queries = [];
    $this->get($endpoint)->assertNotFound();
    expect($reportReads())->toBe([]);
});

test('a legacy report without a snapshot never gains current action markers', function () {
    $world = MetricsWorld::create();
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id, 'locale' => 'en']);
    $report = Report::factory()->create(['report_schedule_id' => $schedule->id,
        'period_start' => '2026-10-01', 'period_end' => '2026-10-07', 'web_snapshot' => null, 'action_section' => null]);
    AggDaily::factory()->create(['market_id' => $world->market->id, 'engine_key' => $world->engine->key,
        'prompt_id' => $world->p1->id, 'date' => '2026-10-03', 'responses' => 20, 'brand_mentioned' => 10]);
    Action::factory()->create(['brand_id' => $world->brand->id, 'title' => 'Unpublished current action marker',
        'started_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-10-04 12:00:00']);
    $token = bin2hex(random_bytes(32));
    $link = ReportLink::query()->create(['report_id' => $report->id, 'public_id' => bin2hex(random_bytes(16)),
        'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->get(route('reports.shared', ['tenant' => $world->tenant->id, 'selector' => $link->public_id]))->assertOk()
        ->assertDontSee('Unpublished current action marker')->assertDontSee('data-marker-date', false);
});
