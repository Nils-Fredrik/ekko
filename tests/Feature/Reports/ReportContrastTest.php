<?php

use App\Models\AggDaily;
use App\Models\Engine;
use App\Models\ReportSchedule;
use App\Reports\ReadableColor;
use App\Reports\ReportPublisher;
use Tests\Support\Metrics\MetricsWorld;

test('the shared report darkens a brand color that is too light on white', function () {
    $html = reportHtml('#e84a38');
    preg_match('/header \{[^}]*background: (#[0-9a-f]{6})/', $html, $header);
    preg_match('/h2 \{[^}]*color: (#[0-9a-f]{6})/', $html, $heading);

    expect($header[1] ?? null)->toBe($heading[1] ?? null)
        ->and($header[1])->not->toBe('#e84a38')
        ->and(ReadableColor::contrast('#ffffff', $header[1]))->toBeGreaterThanOrEqual(ReadableColor::MINIMUM)
        ->and($html)->toContain('min-height: 24px');
});

test('section bars are the same markup on the web page and in the pdf', function () {
    $base = [
        'brand' => 'Northwind',
        'from' => '2026-10-01',
        'to' => '2026-10-31',
        'profile' => ['name' => 'Northwind', 'color' => '#112233', 'logo' => null],
        'insight' => null,
        'responses' => 10,
        'headlines' => [],
        'engines' => [[
            'engine' => 'ChatGPT',
            'visibility' => ['text' => '40 % n 10', 'low' => false, 'ratio' => 0.4],
            'share_of_voice' => ['text' => '20 % n 10', 'low' => false, 'ratio' => 0.2],
        ]],
        'prompts' => [],
        'competitors' => [],
        'sources' => [['domain' => 'example.com', 'citations' => 4]],
        'gaps' => [],
        'bots' => [],
        'traffic' => [],
        'models' => [],
        'actions' => [
            'title' => 'Actions',
            'open_title' => 'Open',
            'done_title' => 'Done',
            'empty_open' => 'None open',
            'empty_done' => 'None done',
            'change_label' => 'Change',
            'open' => [],
            'completed' => [],
        ],
    ];

    $charts = [
        'competitor_series' => [
            'periods' => ['2026-10-01', '2026-10-02'],
            'lines' => [['name' => 'Northwind', 'own' => true, 'points' => [0.2, 0.4], 'ranks' => [1, 2]]],
        ],
        'heatmap' => [
            'engines' => ['chatgpt'],
            'rows' => [['prompt' => 'Who leads?', 'cells' => [0.5]]],
        ],
        'funnel' => [
            ['label' => 'Fetched', 'value' => 12, 'low' => false],
            ['label' => 'Cited', 'value' => 4, 'low' => true],
            ['label' => 'Visits', 'value' => null, 'low' => true],
        ],
    ];
    $pdf = view('reports.pdf', [...$base, ...$charts])->render();
    $shared = view('reports.pdf', [...$base, ...$charts, 'shared' => true])->render();

    expect($pdf)->toContain('data-testid="report-competitor-lines"')
        ->toContain('data-testid="report-competitor-ranks"')
        ->toContain('points="40,10 560,150"')
        ->toContain('data-testid="report-prompt-heatmap"')
        ->toContain('data-testid="report-bars-funnel"')
        ->and($shared)->toContain('data-testid="report-competitor-lines"')
        ->toContain('data-testid="report-prompt-heatmap"')
        ->toContain('data-testid="report-bars-funnel"')
        ->and($pdf)->toContain('data-testid="report-bars-engines"')
        ->toContain('data-testid="report-bars-sources"')
        ->and($shared)->toContain('data-testid="report-bars-engines"')
        ->toContain('data-testid="report-bars-sources"');
});

test('report charts keep sample size, rank and distinguishable engine names', function () {
    $world = MetricsWorld::create();
    $world->engine->forceFill(['key' => 'chatgpt_api', 'family' => 'chatgpt'])->save();
    $web = Engine::factory()->create(['key' => 'chatgpt_ui', 'family' => 'chatgpt']);
    $world->p1->forceFill(['text' => 'Who leads the category?'])->save();
    $base = ['market_id' => $world->market->id, 'prompt_id' => $world->p1->id];
    AggDaily::factory()->create([...$base, 'engine_key' => 'chatgpt_api', 'date' => '2026-11-02', 'responses' => 4, 'brand_mentioned' => 1,
        'competitor_mentions' => [$world->c1->id => 2]]);
    AggDaily::factory()->create([...$base, 'engine_key' => 'chatgpt_ui', 'date' => '2026-11-02', 'responses' => 10, 'brand_mentioned' => 5,
        'competitor_mentions' => [$world->c1->id => 1]]);
    AggDaily::factory()->create([...$base, 'engine_key' => 'chatgpt_api', 'date' => '2026-11-03', 'responses' => 2, 'brand_mentioned' => 0,
        'competitor_mentions' => [$world->c1->id => 1]]);
    $schedule = ReportSchedule::factory()->create(['brand_id' => $world->brand->id]);
    app()->setLocale('nb');

    $data = app(ReportPublisher::class)->viewData($world->tenant, $schedule, '2026-11-01', '2026-11-08', null);
    $html = view('reports.pdf', $data)->render();
    $shared = view('reports.pdf', [...$data, 'shared' => true])->render();
    $brand = collect($data['competitor_series']['lines'])->firstWhere('own', true);
    $heatmap = collect($data['heatmap']['rows'])->firstWhere('prompt', 'Who leads the category?');
    $cells = array_combine($data['heatmap']['engines'], $heatmap['cells']);

    expect($data['heatmap']['engines'])->toBe(['ChatGPT API', 'ChatGPT'])
        ->and($cells['ChatGPT API']['text'])->toBe('16,7 % n 6')
        ->and($cells['ChatGPT API']['low'])->toBeTrue()
        ->and($cells['ChatGPT']['text'])->toBe('50,0 % n 10')
        ->and($cells['ChatGPT']['low'])->toBeFalse()
        ->and($brand['ranks'])->toBe([1, 2])
        ->and($brand['lows'])->toBe([false, true])
        ->and($data['funnel'])->toHaveCount(3)
        ->and($html)->toContain('16,7 % n 6')->toContain('data-low-sample')
        ->and($shared)->toContain('16,7 % n 6')->toContain('data-low-sample')
        ->and($shared)->not->toContain('shared-report.js');
});

test('a brand color that already clears AA is rendered unchanged', function () {
    $html = reportHtml('#112233');

    expect($html)->toContain('background: #112233')
        ->and($html)->toContain('h2 { margin: 22px 0 8px; font-size: 14px; color: #112233; }');
});

function reportHtml(string $color): string
{
    return view('reports.pdf', [
        'brand' => 'Northwind',
        'from' => '2026-10-01',
        'to' => '2026-10-31',
        'profile' => ['name' => 'Northwind', 'color' => $color, 'logo' => null],
        'insight' => null,
        'responses' => 0,
        'headlines' => [],
        'engines' => [],
        'prompts' => [],
        'competitors' => [],
        'sources' => [],
        'gaps' => [],
        'bots' => [],
        'traffic' => [],
        'models' => [],
        'actions' => [
            'title' => 'Actions',
            'open_title' => 'Open',
            'done_title' => 'Done',
            'empty_open' => 'None open',
            'empty_done' => 'None done',
            'change_label' => 'Change',
            'open' => [],
            'completed' => [],
        ],
    ])->render();
}
