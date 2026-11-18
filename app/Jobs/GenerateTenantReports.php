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
use App\Reports\InsightPending;
use App\Reports\InsightWriter;
use App\Reports\ReportPublisher;
use App\Tenancy\Rls\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Generates the latest completed report for each schedule and sends it (docs/spec.md 11.2).
 * The Sonnet call and the PDF render stay outside the database transaction. A paid insight is
 * kept on disk, so a failed render is retried without a second model call. A stored report
 * that was not sent is sent again on the next run.
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
        $lock = Cache::lock('reports:tenant:'.$this->tenantId, 900);
        if (! $lock->get()) {
            throw new RuntimeException('Another report run is already handling this tenant.');
        }

        try {
            $this->generate($rls, $publisher, $insights);
        } finally {
            $lock->release();
        }
    }

    private function generate(TenantRls $rls, ReportPublisher $publisher, InsightWriter $insights): void
    {
        $now = CarbonImmutable::now('UTC');
        $tenant = Team::query()->findOrFail($this->tenantId);
        /** @var list<DueReport> $due */
        $due = $rls->run([$this->tenantId], fn (): array => $publisher->due($tenant, $now));

        $failed = false;
        foreach ($due as $item) {
            try {
                $text = $this->insight($rls, $insights, $item);
            } catch (InsightPending $pending) {
                report($pending);
                $failed = true;

                continue;
            }
            try {
                /** @var array{path: string, data: array<string, mixed>} $document */
                $document = $rls->run([$this->tenantId], fn (): array => $publisher->document($tenant, $item, $text));
                $publisher->write($document['path'], $document['data'], $item->locale);
                $rls->run([$this->tenantId], fn () => $publisher->record($item, $document['path']));
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        /** @var list<Report> $unsent */
        $unsent = $rls->run([$this->tenantId], fn (): array => $publisher->unsent($tenant, $now));
        foreach ($unsent as $report) {
            try {
                $this->send($rls, $report);
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        // The paid insight stays on disk. A failed render must fail the command so the
        // same period is retried, instead of waiting until the next week selects another one.
        if ($failed) {
            throw new RuntimeException('A report PDF could not be rendered.');
        }
    }

    private function insight(TenantRls $rls, InsightWriter $insights, DueReport $item): ?string
    {
        if ($item->cadence !== 'monthly') {
            return null;
        }

        $disk = config()->string('ekko.reports.disk');
        $path = 'reports/'.$this->tenantId.'/'.$item->scheduleId.'-'.$item->from.'.insight.json';
        $saved = $insights->remembered($disk, $path);
        if ($saved !== null) {
            $insights->settleBooked($saved['operation'], $saved['cost'], $saved['overage']);

            return $saved['text'] !== '' ? $saved['text'] : null;
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
            $pending = $rls->run([$this->tenantId], function () use ($insights, $item): bool {
                $brand = Brand::query()->find($item->brandId);

                return $brand instanceof Brand && $insights->claimed($brand);
            });
            if ($pending) {
                throw new InsightPending('The insight claim is still open.');
            }

            return null;
        }

        $previous = app()->getLocale();
        app()->setLocale($item->locale);
        try {
            $draft = $insights->draft($loaded['admission'], $loaded['name'], $item->summary, $item->locale);
        } catch (Throwable $e) {
            // Unknown outcome, including a timeout: keep the claim and any overage reservation.
            report($e);

            return null;
        } finally {
            app()->setLocale($previous);
        }
        if (! $draft instanceof InsightDraft) {
            $rls->run([$this->tenantId], fn () => $insights->release($loaded['admission']));

            return null;
        }

        $rls->run([$this->tenantId], fn () => $insights->book($draft));
        $insights->remember($disk, $path, $draft);
        $insights->settleBooked($draft->admission->operation, $draft->cost, $draft->admission->overageOperation);

        return $draft->text !== '' ? $draft->text : null;
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
        $recipients = array_values(array_filter(
            $schedule->recipients,
            fn (string $recipient): bool => $recipient !== '',
        ));
        if ($recipients === []) {
            return;
        }

        $claimed = $rls->run([$this->tenantId], fn (): int => Report::query()
            ->whereKey($report->id)
            ->whereNull('sent_at')
            ->update(['sent_at' => CarbonImmutable::now('UTC')]));
        if ($claimed !== 1) {
            return;
        }

        try {
            foreach ($recipients as $recipient) {
                Mail::to($recipient)->locale($schedule->locale)->send(new ReportMail(
                    $brand instanceof Brand ? $brand->name : '',
                    $report->period_start->toDateString(),
                    $report->period_end->toDateString(),
                    $pdf,
                ));
            }
        } catch (Throwable $e) {
            $rls->run([$this->tenantId], fn () => $report->forceFill(['sent_at' => null])->save());

            throw $e;
        }
    }
}
