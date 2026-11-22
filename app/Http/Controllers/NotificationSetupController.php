<?php

namespace App\Http\Controllers;

use App\Alerts\AlertType;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\ReportSchedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alerts and report plans for one brand (docs/ux-arbeidsflate.md 5.7, U26).
 * Reading the page does not create rules. The first edit does, through the existing upsert.
 */
class NotificationSetupController extends Controller
{
    public function __invoke(Brand $brand): Response
    {
        Gate::authorize('view', $brand);
        $reports = Report::query()->whereIn('report_schedule_id', $brand->reportSchedules()->select('id'))
            ->latest('period_end')->limit(30)->get();
        $links = ReportLink::query()->whereIn('report_id', $reports->modelKeys())->latest()->get()->groupBy('report_id');
        $canReports = Gate::allows('create', ReportSchedule::class);

        return Inertia::render('Brands/Setup/Notifications', [
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'tenant' => $brand->tenant->name,
            'rules' => array_map(fn (AlertType $type): array => $this->rule($brand, $type), AlertType::cases()),
            'schedules' => $brand->reportSchedules()->orderBy('id')->get()->map(fn (ReportSchedule $schedule): array => [
                'id' => $schedule->id,
                'cadence' => $schedule->cadence,
                'recipients' => $schedule->recipients,
                'locale' => $schedule->locale,
                'branding' => $schedule->branding,
            ])->values(),
            'reports' => $reports->map(fn (Report $report): array => [
                'id' => $report->id,
                'period_start' => $report->period_start->toDateString(),
                'period_end' => $report->period_end->toDateString(),
                'sent_at' => $report->sent_at?->toIso8601String(),
                'downloadable' => ($path = $this->storedPath($report)) !== null
                    && Storage::disk(config()->string('ekko.reports.disk'))->exists($path),
                'links' => $links->get($report->id, collect())->map(fn (ReportLink $link): array => [
                    'id' => $link->id,
                    'expires_at' => $link->expires_at->toIso8601String(),
                    'revoked_at' => $link->revoked_at?->toIso8601String(),
                ])->values(),
            ])->values(),
            'locales' => array_values(array_filter(config()->array('ekko.locales'), fn (mixed $locale): bool => is_string($locale))),
            'canAlerts' => Gate::allows('create', AlertRule::class),
            'canReports' => $canReports,
        ]);
    }

    /** @return array<string, mixed> */
    private function rule(Brand $brand, AlertType $type): array
    {
        $existing = $this->existingRule($brand, $type);
        $stored = $existing === null
            ? ($type === AlertType::IntegrationError ? ['in_app'] : config()->array('ekko.alerts.default_channels'))
            : $existing->channels;
        $channels = array_map(fn (mixed $channel): string => $channel === 'in_app' ? 'inbox' : (string) $channel, $stored);

        return [
            'type' => $type->value,
            'tenant_wide' => $type->isTenantWide(),
            'unit' => match ($type) {
                AlertType::VisibilityDrop => 'points',
                AlertType::CompetitorTakeover, AlertType::NewCompetitor => 'percent',
                default => null,
            },
            'is_active' => $existing === null ? true : $existing->is_active,
            'threshold' => $existing === null ? $type->defaultThreshold() : $existing->threshold,
            'channels' => array_values(array_unique(['inbox', ...array_intersect($channels, ['inbox', 'email', 'slack'])])),
        ];
    }

    private function existingRule(Brand $brand, AlertType $type): ?AlertRule
    {
        $query = AlertRule::query()->where('type', $type->value);
        if ($type === AlertType::IntegrationError) {
            return $query->where(fn ($inner) => $inner->whereNull('brand_id')->orWhere('brand_id', $brand->id))
                ->orderByRaw('brand_id IS NULL')->orderBy('id')->first();
        }

        return $query->where('brand_id', $type->isTenantWide() ? null : $brand->id)->orderBy('id')->first();
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
