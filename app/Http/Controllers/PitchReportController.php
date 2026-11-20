<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Team;
use App\Pitch\PitchReportMetrics;
use App\Reports\ReportActions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PitchReportController extends Controller
{
    public function __invoke(Brand $brand, PitchReportMetrics $metrics, ReportActions $actions): Response
    {
        return Inertia::render('Pitch/Report', $this->data($brand, $metrics, $actions));
    }

    public function download(Brand $brand, PitchReportMetrics $metrics, ReportActions $actions): StreamedResponse
    {
        $data = $this->pdfData($this->data($brand, $metrics, $actions), Team::query()->findOrFail(current_tenant_id()));
        $path = 'reports/'.current_tenant_id().'/pitch-'.$brand->id.'.pdf';
        $locale = app()->getLocale();

        // The stream is rendered after the tenant RLS transaction has committed; its data is already scoped.
        return response()->streamDownload(function () use ($data, $path, $locale): void {
            $previous = app()->getLocale();
            app()->setLocale($locale);
            try {
                $bytes = Pdf::view('reports.pdf', $data)->format('a4')->generatePdfContent();
                if (! Storage::disk(config()->string('ekko.reports.disk'))->put($path, $bytes, 'private')) {
                    throw new RuntimeException('Could not store the pitch report PDF.');
                }
                echo $bytes;
            } finally {
                app()->setLocale($previous);
            }
        }, 'pitch-report.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** @return array<string, mixed> */
    private function data(Brand $brand, PitchReportMetrics $metrics, ReportActions $actions): array
    {
        Gate::authorize('view', $brand);
        $team = Team::query()->findOrFail(current_tenant_id());
        abort_unless($team->pitch_expires_at !== null, 404);
        $start = CarbonImmutable::parse($team->created_at)->utc();
        $now = CarbonImmutable::now('UTC');
        $end = $now->min($team->pitch_expires_at);
        if ($team->pitch_converted_at !== null) {
            $end = $end->min($team->pitch_converted_at);
        }
        $from = $start->setTimezone($team->timezone)->toDateString();
        $to = $end->setTimezone($team->timezone)->toDateString();
        // A run created as a pitch stays a pitch even if its answer arrives after conversion.
        $runCutoff = $end->equalTo($now) ? $now->addSecond() : $end;
        $summary = $metrics->forWindow($brand, $start, $now->addSecond(), $runCutoff);
        $section = $actions->section($brand->id, $from, $to);
        $section['open'] = array_slice($section['open'], 0, 3);
        $section['completed'] = [];

        return [
            'brand' => $brand->name,
            'brandId' => $brand->id,
            'from' => $from,
            'to' => $to,
            ...$summary,
            'actions' => $section,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function pdfData(array $data, Team $team): array
    {
        $metric = function (array $value): array {
            $shown = $value['value'] === null
                ? '–'
                : number_format((float) $value['value'] * 100, 1, app()->getLocale() === 'en' ? '.' : ',', ' ').' %';

            return [
                'text' => $shown.' '.__('reports.sample', ['n' => $value['n']]),
                'low' => (bool) $value['low_sample'],
                'ratio' => $value['value'],
            ];
        };
        $branding = is_array($team->branding) ? $team->branding : [];
        $color = is_string($branding['primary'] ?? null) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $branding['primary']) === 1
            ? $branding['primary']
            : '#e84a38';
        /** @var array{value: float|null, n: int, low_sample: bool} $visibility */
        $visibility = $data['visibility'];
        /** @var list<array{name: string, mentions: int, visibility: array{value: float|null, n: int, low_sample: bool}}> $competitors */
        $competitors = $data['competitors'];
        $rows = [[
            'name' => (string) $data['brand'],
            'mentions' => (int) $data['mentions'],
            'visibility' => $metric($visibility),
        ]];
        foreach ($competitors as $row) {
            $rows[] = [
                'name' => $row['name'],
                'mentions' => $row['mentions'],
                'visibility' => $metric($row['visibility']),
            ];
        }

        return [
            ...$data,
            'short' => true,
            'profile' => ['name' => $team->name, 'color' => $color, 'logo' => null],
            'insight' => null,
            'responses' => $visibility['n'],
            'headlines' => [[
                'label' => __('pitch.visibility'),
                'value' => $metric($visibility)['text'],
                'low' => $visibility['low_sample'],
                'change' => '',
            ]],
            'competitors' => $rows,
        ];
    }
}
