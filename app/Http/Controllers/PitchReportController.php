<?php

namespace App\Http\Controllers;

use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Brand;
use App\Models\Team;
use App\Reports\ReportActions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class PitchReportController extends Controller
{
    public function __invoke(Brand $brand, MetricsService $metrics, ReportActions $actions): Response
    {
        Gate::authorize('view', $brand);
        $team = Team::query()->findOrFail(current_tenant_id());
        abort_unless($team->pitch_expires_at !== null, 404);
        $from = CarbonImmutable::parse($team->created_at)->setTimezone($team->timezone)->toDateString();
        $today = CarbonImmutable::now($team->timezone)->toDateString();
        $to = min($today, $team->pitch_expires_at->setTimezone($team->timezone)->toDateString());
        $filter = new MetricsFilter($brand->id, $from, $to);
        $section = $actions->section($brand->id, $from, $to);
        $section['open'] = array_slice($section['open'], 0, 3);
        $section['completed'] = [];

        return Inertia::render('Pitch/Report', [
            'brand' => $brand->name,
            'from' => $from,
            'to' => $to,
            'visibility' => $metrics->visibility($filter)->toArray(),
            'competitors' => array_map(fn (array $row): array => [
                'name' => $row['name'], 'visibility' => $row['visibility']->toArray(),
            ], $metrics->competitorComparison($filter)),
            'sources' => $metrics->sourceShare($filter, 10),
            'actions' => $section,
        ]);
    }
}
