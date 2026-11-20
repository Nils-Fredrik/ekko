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
        $data = $this->data($brand, $metrics, $actions);
        $path = 'reports/'.current_tenant_id().'/pitch-'.$brand->id.'.pdf';
        $locale = app()->getLocale();

        // The stream is rendered after the tenant RLS transaction has committed; its data is already scoped.
        return response()->streamDownload(function () use ($data, $path, $locale): void {
            $previous = app()->getLocale();
            app()->setLocale($locale);
            try {
                $bytes = Pdf::view('pitch.pdf', $data)->format('a4')->generatePdfContent();
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
}
