<?php

namespace App\Reports;

use App\Metrics\Metric;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Metrics\MetricsSummary;
use App\Models\Prompt;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Team;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

/** Builds, stores and describes a report PDF (docs/spec.md 11.2). Mail is sent by the job, after this returns. */
final class ReportPublisher
{
    public function __construct(
        private readonly MetricsService $metrics,
        private readonly ReportActions $actions,
    ) {}

    /** @return list<DueReport> */
    public function due(Team $tenant, CarbonImmutable $now): array
    {
        $timezone = $this->timezone($tenant);
        $due = [];
        foreach (ReportSchedule::query()->orderBy('id')->get() as $schedule) {
            $windows = ReportWindow::recent($schedule->cadence, $timezone, $now);
            $open = [];
            foreach ($windows as $index => [$from, $to]) {
                $report = Report::query()
                    ->where('report_schedule_id', $schedule->id)
                    ->whereDate('period_start', $from)
                    ->whereDate('period_end', $to)
                    ->first();
                $stored = $report instanceof Report && is_string($report->pdf_uri) && $report->pdf_uri !== '';
                if ($stored) {
                    continue;
                }
                // Only the latest period starts by itself. An older one is retried after a failed attempt stored a row.
                if ($index > 0 && ! $report instanceof Report) {
                    continue;
                }
                $open[] = [$from, $to];
            }
            foreach (array_reverse($open) as [$from, $to]) {
                $filter = new MetricsFilter($schedule->brand_id, $from, $to);
                $summary = $this->metrics->summary($filter);
                $due[] = new DueReport(
                    $schedule->id,
                    $schedule->brand_id,
                    $schedule->cadence,
                    $schedule->locale,
                    $schedule->recipients,
                    $from,
                    $to,
                    [
                        'responses' => $summary->responses,
                        'visibility' => $summary->visibility->value,
                        'share_of_voice' => $summary->shareOfVoice->value,
                    ],
                );
            }
        }

        return $due;
    }

    /**
     * Metric queries for one PDF. Call this inside tenant row-level security, and render afterwards.
     *
     * @return array{path: string, data: array<string, mixed>}
     */
    public function document(Team $tenant, DueReport $due, ?string $insight): array
    {
        $schedule = ReportSchedule::query()->with('brand')->findOrFail($due->scheduleId);
        $previous = app()->getLocale();
        // Freeze the language selected when the due report was queued, even if its schedule changes mid-run.
        app()->setLocale($due->locale);
        try {
            return [
                'path' => 'reports/'.$tenant->id.'/'.$schedule->id.'-'.$due->from.'.pdf',
                'data' => $this->viewData($tenant, $schedule, $due->from, $due->to, $insight),
            ];
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function write(string $path, array $data, string $locale): void
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            Pdf::view('reports.pdf', $data)
                ->format('a4')
                ->disk(config()->string('ekko.reports.disk'), 'private')
                ->save($path);
        } finally {
            app()->setLocale($previous);
        }
    }

    /** Marks the period as started so a later run retries it when the PDF is still missing. */
    public function claim(DueReport $due): Report
    {
        $existing = $this->find($due);
        if ($existing instanceof Report) {
            return $existing;
        }

        return ReportSchedule::query()->findOrFail($due->scheduleId)->reports()->create([
            'period_start' => $due->from,
            'period_end' => $due->to,
            'pdf_uri' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $actions  the section drawn in this PDF, kept so the web page shows the same report
     * @param  array<string, mixed>|null  $snapshot
     */
    public function record(DueReport $due, string $path, array $actions, ?array $snapshot = null): Report
    {
        $existing = $this->find($due);
        if ($existing instanceof Report) {
            $existing->forceFill(['pdf_uri' => $path, 'action_section' => $actions, 'web_snapshot' => $snapshot])->save();

            return $existing;
        }

        return ReportSchedule::query()->findOrFail($due->scheduleId)->reports()->create([
            'period_start' => $due->from,
            'period_end' => $due->to,
            'pdf_uri' => $path,
            'action_section' => $actions,
            'web_snapshot' => $snapshot,
        ]);
    }

    private function find(DueReport $due): ?Report
    {
        $report = Report::query()
            ->where('report_schedule_id', $due->scheduleId)
            ->whereDate('period_start', $due->from)
            ->whereDate('period_end', $due->to)
            ->first();

        return $report instanceof Report ? $report : null;
    }

    /** @return list<Report> */
    public function unsent(Team $tenant, CarbonImmutable $now): array
    {
        $timezone = $this->timezone($tenant);
        $reports = [];
        foreach (ReportSchedule::query()->orderBy('id')->get() as $schedule) {
            $windows = ReportWindow::recent($schedule->cadence, $timezone, $now);
            $oldest = $windows[array_key_last($windows)][0];
            $pending = Report::query()
                ->with('reportSchedule.brand')
                ->where('report_schedule_id', $schedule->id)
                ->whereNotNull('pdf_uri')
                ->where('pdf_uri', '!=', '')
                ->whereNull('sent_at')
                ->whereDate('period_end', '>=', $oldest)
                ->orderBy('period_start')
                ->get();
            foreach ($pending as $report) {
                $reports[] = $report;
            }
        }

        return $reports;
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(Team $tenant, ReportSchedule $schedule, string $from, string $to, ?string $insight): array
    {
        $schedule->loadMissing('brand');
        $current = new MetricsFilter($schedule->brand_id, $from, $to);
        [$previousFrom, $previousTo] = ReportWindow::compared($schedule->cadence, $from, $to);
        $previous = new MetricsFilter($schedule->brand_id, $previousFrom, $previousTo);
        $now = $this->metrics->summary($current);
        $then = $this->metrics->summary($previous);

        return [
            // Labels and formatted values below are generated in the current locale.
            'locale' => app()->getLocale(),
            'brand' => $schedule->brand->name,
            'from' => $from,
            'to' => $to,
            'profile' => $this->profile($tenant, $schedule),
            'insight' => $insight,
            'headlines' => [
                $this->headline('reports.visibility', $now->visibility, $then->visibility, true),
                $this->headline('reports.share_of_voice', $now->shareOfVoice, $then->shareOfVoice, true),
                $this->headline('reports.recommendation', $now->recommendationRate, $then->recommendationRate, true),
                $this->headline('reports.position', $now->averagePosition, $then->averagePosition, false),
                $this->headline('reports.first_place', $now->firstPlaceRate, $then->firstPlaceRate, true),
                $this->headline('reports.citation', $now->citationRate, $then->citationRate, true),
                $this->headline('reports.sentiment', $now->sentiment, $then->sentiment, false),
            ],
            'responses' => $now->responses,
            // Keep the raw daily metrics alongside the PDF data so a future interactive
            // rendering does not silently recompute a historical report from newer aggregates.
            'trend' => array_map(fn (array $point): array => [
                'period' => $point['period'],
                ...$point['summary']->toArray(),
            ], $this->metrics->series($current)),
            'engines' => $this->engines($current),
            'prompts' => $this->prompts($current, $previous),
            'competitors' => $this->competitors($current),
            'sources' => $this->metrics->sourceShare($current, 15),
            'gaps' => $this->metrics->sourceGap($current, 15),
            'bots' => $this->metrics->botHits($current),
            'traffic' => $this->metrics->aiTraffic($current),
            'models' => $this->metrics->modelChanges($from, $to)->map(fn ($change): array => [
                'engine' => $change->engine_key,
                'on' => $change->changed_on->toDateString(),
                'from' => $change->from_model,
                'to' => $change->to_model,
            ])->all(),
            'actions' => $this->actions->section($schedule->brand_id, $from, $to),
        ];
    }

    /** @return array{label: string, value: string, low: bool, change: string} */
    private function headline(string $label, Metric $current, Metric $previous, bool $rate): array
    {
        $shown = $this->sample($current, $rate);

        return [
            'label' => __($label),
            'value' => $shown['text'],
            'low' => $shown['low'],
            'change' => $this->delta($current->value, $previous->value, $rate),
        ];
    }

    /**
     * A percentage keeps its sample size, and a small sample is faded (docs/spec.md 9).
     *
     * @return array{text: string, low: bool}
     */
    private function sample(Metric $metric, bool $rate): array
    {
        $value = $rate ? $this->percent($metric->value) : $this->number($metric->value, 1);

        return [
            'text' => $value.' '.__('reports.sample', ['n' => $metric->n]),
            'low' => $metric->isLowSample(),
        ];
    }

    /** @return list<array{engine: string, visibility: array{text: string, low: bool}, share_of_voice: array{text: string, low: bool}}> */
    private function engines(MetricsFilter $filter): array
    {
        $rows = $this->metrics->breakdown($filter, 'engine');
        $engines = [];
        foreach ($rows as $key => $summary) {
            $engines[] = [
                'engine' => (string) $key,
                'visibility' => $this->sample($summary->visibility, true),
                'share_of_voice' => $this->sample($summary->shareOfVoice, true),
            ];
        }

        return $engines;
    }

    /** @return list<array{prompt: string, visibility: array{text: string, low: bool}, change: string}> */
    private function prompts(MetricsFilter $current, MetricsFilter $previous): array
    {
        $now = $this->metrics->breakdown($current, 'prompt');
        $then = $this->metrics->breakdown($previous, 'prompt');
        $ranked = [];
        foreach ($now as $id => $summary) {
            $earlier = $then[$id] ?? null;
            $before = $earlier instanceof MetricsSummary ? $earlier->visibility->value : null;
            $after = $summary->visibility->value;
            $ranked[] = [
                'id' => (int) $id,
                'magnitude' => ($after !== null && $before !== null) ? abs($after - $before) : -1,
                'visibility' => $this->sample($summary->visibility, true),
                'change' => $this->delta($after, $before, true),
            ];
        }
        usort($ranked, fn (array $a, array $b): int => $b['magnitude'] <=> $a['magnitude']);
        $ranked = array_slice($ranked, 0, 10);
        $names = Prompt::query()->whereIn('id', array_column($ranked, 'id'))->pluck('text', 'id');

        return array_map(fn (array $row): array => [
            'prompt' => (string) ($names[$row['id']] ?? $row['id']),
            'visibility' => $row['visibility'],
            'change' => $row['change'],
        ], $ranked);
    }

    /** @return list<array{name: string, mentions: int, visibility: array{text: string, low: bool}, share_of_voice: array{text: string, low: bool}}> */
    private function competitors(MetricsFilter $filter): array
    {
        return array_map(fn (array $row): array => [
            'name' => $row['name'],
            'mentions' => $row['mentions'],
            'visibility' => $this->sample($row['visibility'], true),
            'share_of_voice' => $this->sample($row['share_of_voice'], true),
        ], $this->metrics->competitorComparison($filter));
    }

    /** @return array{name: string, color: string, logo: string|null} */
    private function profile(Team $tenant, ReportSchedule $schedule): array
    {
        $source = $tenant;
        if ($schedule->branding === 'partner' && $tenant->partner_team_id !== null) {
            $partner = Team::query()->find($tenant->partner_team_id);
            if ($partner instanceof Team) {
                $source = $partner;
            }
        }
        $branding = $source->branding ?? [];
        $color = is_string($branding['primary'] ?? null) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $branding['primary']) === 1
            ? $branding['primary']
            : '#e84a38';

        return [
            'name' => $source->name,
            'color' => $color,
            'logo' => $this->logo($source->id, $branding['logo'] ?? null),
        ];
    }

    private function logo(int $teamId, mixed $path): ?string
    {
        $prefix = 'branding/'.$teamId.'/';
        if (! is_string($path) || ! str_starts_with($path, $prefix) || str_contains($path, '..')) {
            return null;
        }
        $storage = Storage::disk(config()->string('ekko.reports.disk'));
        if (! $storage->exists($path)) {
            return null;
        }
        $bytes = $storage->get($path);
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/png';
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function percent(?float $value): string
    {
        return $value === null ? __('reports.empty_value') : $this->number($value * 100, 1).' %';
    }

    private function number(?float $value, int $decimals): string
    {
        if ($value === null) {
            return __('reports.empty_value');
        }
        $decimal = app()->getLocale() === 'en' ? '.' : ',';

        return number_format($value, $decimals, $decimal, ' ');
    }

    private function delta(?float $current, ?float $previous, bool $rate): string
    {
        if ($current === null || $previous === null) {
            return __('reports.empty_value');
        }
        $diff = $rate ? ($current - $previous) * 100 : ($current - $previous);
        $sign = $diff > 0 ? '+' : '';

        return $sign.$this->number($diff, 1).($rate ? ' pp' : '');
    }

    private function timezone(Team $tenant): DateTimeZone
    {
        try {
            return new DateTimeZone($tenant->timezone);
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
