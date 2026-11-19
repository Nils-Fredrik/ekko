<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Team;
use App\Pitch\PitchReportMetrics;
use App\Reports\ReportActions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class PitchReportController extends Controller
{
    public function __invoke(Brand $brand, PitchReportMetrics $metrics, ReportActions $actions): Response
    {
        Gate::authorize('view', $brand);
        $team = Team::query()->findOrFail(current_tenant_id());
        abort_unless($team->pitch_expires_at !== null, 404);
        $start = CarbonImmutable::parse($team->created_at)->utc();
        $end = CarbonImmutable::now('UTC')->min($team->pitch_expires_at);
        if ($team->pitch_converted_at !== null) {
            $end = $end->min($team->pitch_converted_at);
        }
        $from = $start->setTimezone($team->timezone)->toDateString();
        $to = $end->setTimezone($team->timezone)->toDateString();
        $summary = $metrics->forWindow($brand, $start, $end);
        $section = $actions->section($brand->id, $from, $to);
        $section['open'] = array_slice($section['open'], 0, 3);
        $section['completed'] = [];

        return Inertia::render('Pitch/Report', [
            'brand' => $brand->name,
            'from' => $from,
            'to' => $to,
            ...$summary,
            'actions' => $section,
        ]);
    }
}
