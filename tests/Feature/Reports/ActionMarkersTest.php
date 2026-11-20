<?php

use App\Actions\ActionMarkers;
use App\Models\Action;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\ModelChange;
use App\Models\ReportSchedule;
use App\Reports\DueReport;
use App\Reports\ReportPublisher;
use App\Tenancy\TenantContext;
use Tests\Support\Metrics\MetricsWorld;

test('action markers use tenant days across DST and exclude other brands, tenants and period boundaries', function () {
    $world = MetricsWorld::create('Europe/Oslo');
    $action = Action::factory()->create([
        'brand_id' => $world->brand->id, 'title' => 'Local action',
        'started_at' => '2026-10-24 22:00:00', 'completed_at' => '2026-10-25 22:59:59',
    ]);
    Action::factory()->create(['brand_id' => $world->brand->id, 'started_at' => '2026-10-24 21:59:59', 'completed_at' => '2026-10-25 23:00:00']);
    Action::factory()->create(['brand_id' => Brand::factory()->create()->id, 'started_at' => '2026-10-25 12:00:00']);
    $other = MetricsWorld::create();
    Action::factory()->create(['brand_id' => $other->brand->id, 'started_at' => '2026-10-25 12:00:00']);
    app(TenantContext::class)->set($world->tenant->id);
    app()->setLocale('en');

    $markers = app(ActionMarkers::class)->forPeriod($world->brand->id, '2026-10-25', '2026-10-25', 'Europe/Oslo');
    expect($markers)->toHaveCount(2)
        ->and(array_column($markers, 'date'))->toBe(['2026-10-25', '2026-10-25'])
        ->and(array_column($markers, 'detail'))->toEqualCanonicalizing(['Action started', 'Action completed']);
    foreach ($markers as $marker) {
        expect($marker['href'])->toBe(route('brands.actions.show', [$world->brand, $action]))
            ->and($marker['action_id'])->toBe($action->id);
    }
    expect(app(ActionMarkers::class)->forPeriod($other->brand->id, '2026-10-25', '2026-10-25', 'Europe/Oslo'))->toBe([]);
});

test('reports freeze action descriptions and dates without private links and PDF preserves a marker on a gap day', function () {
    $world = MetricsWorld::create('Europe/Oslo');
    $action = Action::factory()->create([
        'brand_id' => $world->brand->id, 'title' => 'Published action',
        'started_at' => '2026-10-01 22:30:00', 'completed_at' => '2026-10-03 12:00:00',
    ]);
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id, 'locale' => 'en']);
    $due = new DueReport($schedule->id, $world->brand->id, 'weekly', 'en', [], '2026-10-01', '2026-10-03', []);
    $publisher = app(ReportPublisher::class);
    $document = $publisher->document($world->tenant, $due, null);
    $snapshot = $document['data'];
    $snapshot['trend'] = [['period' => '2026-10-01', 'visibility' => ['value' => 0.4, 'n' => 20]]];
    $report = $publisher->record($due, $document['path'], $snapshot['actions'], $snapshot);
    $action->update(['title' => 'Changed later', 'started_at' => null, 'completed_at' => null]);
    $saved = $report->fresh()->web_snapshot;
    expect(array_column($saved['markers'], 'date'))->toBe(['2026-10-02', '2026-10-03'])
        ->and(array_column($saved['markers'], 'label'))->toBe(['Published action', 'Published action'])
        ->and(array_column($saved['markers'], 'href'))->toBe([])
        ->and(array_column($saved['markers'], 'action_id'))->toBe([]);
    $html = view('reports.pdf', $saved)->render();
    expect($html)->toContain('Action started', 'Action completed')->not->toContain('Changed later');
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//*[@data-marker-date="2026-10-02"]')->item(0)->getAttribute('x1'))->toBe('300')
        ->and($xpath->query('//*[@data-marker-date="2026-10-03"]')->item(0)->getAttribute('x1'))->toBe('560');
    $shared = view('reports.pdf', [...$saved, 'shared' => true])->render();
    expect($shared)->toContain('Action started', 'Published action')->not->toContain('brands/'.$world->brand->id.'/actions');

    // Older snapshots stay readable without inventing markers from current actions.
    unset($saved['markers']);
    expect(view('reports.pdf', $saved)->render())->not->toContain('data-marker-date=');
});

test('reports retain model change markers beside action markers', function () {
    $world = MetricsWorld::create();
    $engine = Engine::factory()->create(['key' => 'chatgpt_api']);
    $change = ModelChange::factory()->create(['engine_key' => $engine->key, 'changed_on' => '2026-10-01']);
    Action::factory()->create(['brand_id' => $world->brand->id, 'started_at' => '2026-10-02 12:00:00']);
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id]);
    $data = app(ReportPublisher::class)->viewData($world->tenant, $schedule, '2026-10-01', '2026-10-03', null);
    expect(array_column($data['markers'], 'kind'))->toBe(['model', 'action'])
        ->and($data['markers'][0]['key'])->toBe('model:'.$change->id)
        ->and($data['markers'][0]['date'])->toBe('2026-10-01')
        ->and($data['markers'][0]['label'])->toBe('ChatGPT API')
        ->and($data['markers'][0]['label'])->not->toBe($engine->key)
        ->and($data['markers'][0]['detail'])->toBe('model-a → model-b');
});
