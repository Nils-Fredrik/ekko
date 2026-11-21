<?php

namespace App\Http\Controllers;

use App\Jobs\SendTestReport;
use App\Models\Brand;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\ReportSchedule;
use App\Reports\ReportActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as DownloadResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Report schedules, history and download (docs/spec.md 14.2 and 11.2, T28). Generating the PDF is T32.
 * A stored address is downloaded only when it stays inside this tenant's report prefix.
 */
class ReportController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Report::class);

        $schedules = ReportSchedule::query()->with('brand')->orderBy('id')->get();
        $reports = Report::query()->with('reportSchedule.brand')->latest('period_end')->limit(30)->get();

        return Inertia::render('Reports/Index', [
            'schedules' => $schedules->map(fn (ReportSchedule $schedule): array => [
                'id' => $schedule->id,
                'brand_id' => $schedule->brand_id,
                'brand' => $schedule->brand->name,
                'cadence' => $schedule->cadence,
                'recipients' => $schedule->recipients,
                'locale' => $schedule->locale,
                'branding' => $schedule->branding,
            ])->values(),
            'reports' => $reports->map(fn (Report $report): array => [
                'id' => $report->id,
                'brand' => $report->reportSchedule->brand->name,
                'period_start' => $report->period_start->toDateString(),
                'period_end' => $report->period_end->toDateString(),
                'sent_at' => $report->sent_at?->toIso8601String(),
                'downloadable' => ($path = $this->storedPath($report)) !== null
                    && Storage::disk(config()->string('ekko.reports.disk'))->exists($path),
            ])->values(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'reportLocales' => array_values(array_filter(
                config()->array('ekko.locales'),
                fn (mixed $locale): bool => is_string($locale),
            )),
            'canManage' => Gate::allows('create', ReportSchedule::class),
        ]);
    }

    public function store(Request $request, ?Brand $brand = null): RedirectResponse
    {
        Gate::authorize('create', ReportSchedule::class);
        $data = $this->validated($request, requireBrand: $brand === null);
        $brand ??= Brand::query()->findOrFail($data['brand_id']);
        unset($data['brand_id']);
        $brand->reportSchedules()->create($data);

        return back();
    }

    public function update(Request $request, ReportSchedule $schedule): RedirectResponse
    {
        Gate::authorize('update', $schedule);
        $schedule->update($this->validated($request, requireBrand: false));

        return back();
    }

    public function updateForBrand(Request $request, Brand $brand, ReportSchedule $schedule): RedirectResponse
    {
        abort_unless($schedule->brand_id === $brand->id, 404);

        return $this->update($request, $schedule);
    }

    public function destroy(Brand $brand, ReportSchedule $schedule): RedirectResponse
    {
        abort_unless($schedule->brand_id === $brand->id, 404);
        Gate::authorize('delete', $schedule);
        $schedule->delete();

        return back();
    }

    public function sendTest(Request $request, Brand $brand, ReportSchedule $schedule): RedirectResponse
    {
        abort_unless($schedule->brand_id === $brand->id, 404);
        Gate::authorize('update', $schedule);
        SendTestReport::dispatch($brand->tenant_id, $schedule->id, (int) $request->user()?->id)->afterCommit();

        return back();
    }

    public function show(Report $report, ReportActions $actions): Response
    {
        Gate::authorize('view', $report);
        $report->load('reportSchedule.brand');
        $schedule = $report->reportSchedule;
        $previous = app()->getLocale();
        app()->setLocale($schedule->locale);
        try {
            $section = $actions->published($report->action_section);
        } finally {
            app()->setLocale($previous);
        }

        return Inertia::render('Reports/Show', [
            'brand' => $schedule->brand->name,
            'from' => $report->period_start->toDateString(),
            'to' => $report->period_end->toDateString(),
            'actions' => $section,
            'downloadable' => ($path = $this->storedPath($report)) !== null
                && Storage::disk(config()->string('ekko.reports.disk'))->exists($path),
            'reportId' => $report->id,
            'canShare' => Gate::allows('update', $report),
            'links' => Gate::allows('update', $report) ? ReportLink::query()->where('report_id', $report->id)->latest()->get()->map(fn (ReportLink $link): array => [
                'id' => $link->id,
                'expires_at' => $link->expires_at->toIso8601String(),
                'revoked_at' => $link->revoked_at?->toIso8601String(),
                'views' => $link->views,
            ])->values() : [],
        ]);
    }

    public function download(Report $report): DownloadResponse
    {
        Gate::authorize('view', $report);
        $path = $this->storedPath($report);
        $disk = config()->string('ekko.reports.disk');
        abort_if($path === null || ! Storage::disk($disk)->exists($path), 404);
        $contents = Storage::disk($disk)->get($path);
        abort_if(! is_string($contents), 404);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="report.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{brand_id?: int, cadence: string, recipients: list<string>, locale: string, branding: string}
     */
    private function validated(Request $request, bool $requireBrand = true): array
    {
        $rules = [
            'cadence' => ['required', Rule::in(['weekly', 'monthly'])],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email', 'max:255', 'distinct'],
            'locale' => ['required', Rule::in(config()->array('ekko.locales'))],
            'branding' => ['required', Rule::in(['tenant', 'partner'])],
        ];
        if ($requireBrand) {
            $rules['brand_id'] = ['required', 'integer'];
        }

        /** @var array{brand_id?: int, cadence: string, recipients: list<string>, locale: string, branding: string} $data */
        $data = $request->validate($rules, [], [
            'brand_id' => __('app.workspace.brand'),
            'cadence' => __('app.workspace.cadence'),
            'recipients' => __('app.workspace.recipients'),
            'recipients.*' => __('app.workspace.recipients'),
            'locale' => __('app.workspace.locale'),
            'branding' => __('app.workspace.branding'),
        ]);

        return $data;
    }

    private function storedPath(Report $report): ?string
    {
        $path = $report->pdf_uri;
        $prefix = 'reports/'.((int) $report->getAttribute('tenant_id')).'/';
        if (! is_string($path) || ! str_starts_with($path, $prefix) || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
