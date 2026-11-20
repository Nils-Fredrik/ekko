<?php

use App\Reports\ReadableColor;

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

    $pdf = view('reports.pdf', $base)->render();
    $shared = view('reports.pdf', [...$base, 'shared' => true])->render();

    expect($pdf)->toContain('data-testid="report-bars-engines"')
        ->toContain('data-testid="report-bars-sources"')
        ->and($shared)->toContain('data-testid="report-bars-engines"')
        ->toContain('data-testid="report-bars-sources"');
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
