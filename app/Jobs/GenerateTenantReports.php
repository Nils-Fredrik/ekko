<?php

namespace App\Jobs;

use App\Jobs\Middleware\SetTenantContext;
use App\Mail\ReportMail;
use App\Models\Brand;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Team;
use App\Reports\DueReport;
use App\Reports\InsightAdmission;
use App\Reports\InsightDraft;
use App\Reports\InsightWriter;
use App\Reports\ReportPublisher;
use App\Tenancy\Rls\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Generates the latest completed report for each schedule and sends it (docs/spec.md 11.2).
 * The Sonnet call stays outside the database transaction. A report that was stored but not sent
 * is sent again on the next run, without a second model call.
 */
class GenerateTenantReports extends TenantJob
{
    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(TenantRls $rls, ReportPublisher $publisher, InsightWriter $insights): void
    {
        $now = CarbonImmutable::now('UTC');
        $tenant = Team::query()->findOrFail($this->tenantId);
        /** @var list<DueReport> $due */
        $due = $rls->run([$this->tenantId], fn (): array => $publisher->due($tenant, $now));

        foreach ($due as $item) {
            $draft = $this->insight($rls, $insights, $item);
            $rls->run([$this->tenantId], function () use ($publisher, $insights, $tenant, $item, $draft): void {
                if ($draft instanceof InsightDraft) {
                    $insights->commit($draft);
                }
                $publisher->store($tenant, $item, $draft instanceof InsightDraft && $draft->text !== '' ? $draft->text : null);
            });
        }

        /** @var list<Report> $unsent */
        $unsent = $rls->run([$this->tenantId], fn (): array => $publisher->unsent($tenant, $now));
        foreach ($unsent as $report) {
            $this->send($rls, $report);
        }
    }

    private function insight(TenantRls $rls, InsightWriter $insights, DueReport $item): ?InsightDraft
    {
        if ($item->cadence !== 'monthly') {
            return null;
        }

        /** @var array{name: string, admission: InsightAdmission}|null $loaded */
        $loaded = $rls->run([$this->tenantId], function () use ($insights, $item) {
            $brand = Brand::query()->find($item->brandId);
            if (! $brand instanceof Brand) {
                return null;
            }
            $admission = $insights->admit($brand);

            return $admission === null ? null : ['name' => $brand->name, 'admission' => $admission];
        });
        if ($loaded === null) {
            return null;
        }

        try {
            $draft = $insights->draft($loaded['admission'], $loaded['name'], $item->summary);
        } catch (Throwable $e) {
            report($e);
            $draft = null;
        }
        if ($draft === null) {
            $rls->run([$this->tenantId], fn () => $insights->release($loaded['admission']));
        }

        return $draft;
    }

    private function send(TenantRls $rls, Report $report): void
    {
        $schedule = $report->reportSchedule;
        if (! $schedule instanceof ReportSchedule) {
            return;
        }
        $path = $report->pdf_uri;
        $disk = config()->string('ekko.reports.disk');
        $pdf = is_string($path) ? Storage::disk($disk)->get($path) : null;
        if (! is_string($pdf) || $pdf === '') {
            return;
        }

        $brand = $schedule->brand;
        foreach ($schedule->recipients as $recipient) {
            if ($recipient === '') {
                continue;
            }
            Mail::to($recipient)->locale($schedule->locale)->send(new ReportMail(
                $brand instanceof Brand ? $brand->name : '',
                $report->period_start->toDateString(),
                $report->period_end->toDateString(),
                $pdf,
            ));
        }

        $rls->run([$this->tenantId], fn () => $report->forceFill(['sent_at' => CarbonImmutable::now('UTC')])->save());
    }
}
