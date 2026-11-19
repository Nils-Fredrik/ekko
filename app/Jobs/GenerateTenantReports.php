<?php

namespace App\Jobs;

use App\Jobs\Middleware\SetTenantContext;
use App\Mail\ReportMail;
use App\Models\AnthropicInsightAllocation;
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
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
        $lock = Cache::lock('reports:tenant:'.$this->tenantId, InsightWriter::CLAIM_SECONDS);
        if (! $lock instanceof Lock) {
            throw new RuntimeException('The report lock cannot be renewed.');
        }
        if (! $lock->get()) {
            // Monday the 1st starts the weekly and monthly triggers together. The run that
            // already holds the lock does the work, so this one must not fail the command.
            Log::info('Skipped report generation because another run holds the tenant lock.', [
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        try {
            $this->generate($rls, $publisher, $insights, $lock);
        } finally {
            $lock->release();
        }
    }

    private function generate(TenantRls $rls, ReportPublisher $publisher, InsightWriter $insights, Lock $lock): void
    {
        $now = CarbonImmutable::now('UTC');
        $tenant = Team::query()->findOrFail($this->tenantId);
        /** @var list<DueReport> $due */
        $due = $rls->run([$this->tenantId], fn (): array => $publisher->due($tenant, $now));

        $failed = false;
        /** @var array<int, true> $claimedBrands */
        $claimedBrands = [];
        foreach ($due as $item) {
            try {
                $this->hold($lock);
                $rls->run([$this->tenantId], fn () => $publisher->claim($item));
                $text = $this->insight($rls, $insights, $item, $claimedBrands);
            } catch (Throwable $e) {
                report($e);
                $failed = true;

                continue;
            }
            try {
                $this->hold($lock);
                /** @var array{path: string, data: array<string, mixed>} $document */
                $document = $rls->run([$this->tenantId], fn (): array => $publisher->document($tenant, $item, $text));
                $publisher->write($document['path'], $document['data'], $item->locale);
                $actions = $document['data']['actions'] ?? null;
                $rls->run([$this->tenantId], fn () => $publisher->record($item, $document['path'], is_array($actions) ? $actions : []));
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        /** @var list<Report> $unsent */
        $unsent = $rls->run([$this->tenantId], fn (): array => $publisher->unsent($tenant, $now));
        foreach ($unsent as $report) {
            try {
                $this->hold($lock);
                $this->send($rls, $report);
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        // Cloud Run does not retry the task. Failing the command records the attempt.
        // The next scheduled run finishes periods that were started but not stored or fully sent.
        if ($failed) {
            throw new RuntimeException('A report could not be finished.');
        }
    }

    private function hold(Lock $lock): void
    {
        if (! $lock->refresh(InsightWriter::CLAIM_SECONDS)) {
            throw new RuntimeException('The tenant report lock could not be renewed.');
        }
    }

    /**
     * @param  array<int, true>  $claimedBrands
     */
    private function insight(TenantRls $rls, InsightWriter $insights, DueReport $item, array &$claimedBrands): ?string
    {
        if ($item->cadence !== 'monthly') {
            return null;
        }

        $disk = config()->string('ekko.reports.disk');
        $path = 'reports/'.$this->tenantId.'/'.$item->scheduleId.'-'.$item->from.'.insight.json';
        $saved = $insights->remembered($disk, $path);
        if ($saved !== null) {
            $booked = false;
            $rls->run([$this->tenantId], function () use ($insights, $item, $saved, &$booked): void {
                $cycle = explode(':', $saved['operation'])[2] ?? '';
                $allocation = AnthropicInsightAllocation::query()
                    ->where('brand_id', $item->brandId)
                    ->whereDate('cycle_start', $cycle)
                    ->first();
                if ($allocation instanceof AnthropicInsightAllocation) {
                    $insights->bookStored($saved['operation'], $saved['cost'], $allocation->id, $saved['input_tokens'], $saved['output_tokens']);
                    $booked = true;
                }
            });
            if ($booked) {
                $insights->settleBooked($saved['operation'], $saved['cost'], $saved['overage']);
            } else {
                Log::warning('Insight draft was kept but the allocation is gone, so it was not settled.', [
                    'operation' => $saved['operation'],
                ]);
            }

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
            if (isset($claimedBrands[$item->brandId])) {
                return null;
            }
            $pending = $rls->run([$this->tenantId], function () use ($insights, $item): bool {
                $brand = Brand::query()->find($item->brandId);

                return $brand instanceof Brand && $insights->pending($brand);
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
        } catch (InsightPending $e) {
            // Unknown HTTP outcome, including a timeout: keep the claim and any overage reservation.
            // Later schedules in this run are not waiting on another process.
            $claimedBrands[$item->brandId] = true;
            report($e);

            return null;
        } finally {
            app()->setLocale($previous);
        }
        if (! $draft instanceof InsightDraft) {
            $rls->run([$this->tenantId], fn () => $insights->release($loaded['admission']));

            return null;
        }

        $stored = false;
        try {
            $insights->remember($disk, $path, $draft);
            $stored = true;
        } catch (Throwable $e) {
            report($e);
        }

        try {
            $rls->run([$this->tenantId], fn () => $insights->book($draft));
            $insights->settleBooked($draft->admission->operation, $draft->cost, $draft->admission->overageOperation);
        } catch (Throwable $e) {
            report($e);
            // The draft is on disk, so the next run books it. A storage failure has no file
            // to recover from, and the charge was not recorded either.
            throw $e;
        }

        return $stored && $draft->text !== '' ? $draft->text : null;
    }

    private function send(TenantRls $rls, Report $report): void
    {
        $schedule = $report->reportSchedule;
        if (! $schedule instanceof ReportSchedule) {
            return;
        }
        $path = $report->pdf_uri;
        $disk = config()->string('ekko.reports.disk');
        $pdf = is_string($path) && $path !== '' && Storage::disk($disk)->exists($path)
            ? Storage::disk($disk)->get($path)
            : null;
        if (! is_string($pdf) || $pdf === '') {
            throw new RuntimeException('The stored report PDF could not be read.');
        }

        $brand = $schedule->brand;
        $recipients = array_values(array_filter(
            $schedule->recipients,
            fn (string $recipient): bool => $recipient !== '',
        ));
        $sent = is_array($report->sent_to) ? $report->sent_to : [];
        foreach ($recipients as $recipient) {
            if (in_array($recipient, $sent, true)) {
                continue;
            }
            Mail::to($recipient)->locale($schedule->locale)->send(new ReportMail(
                $brand instanceof Brand ? $brand->name : '',
                $report->period_start->toDateString(),
                $report->period_end->toDateString(),
                $pdf,
            ));
            $sent[] = $recipient;
            $this->markSent($rls, $report, $sent, array_diff($recipients, $sent) === []);
        }
        if ($report->sent_at === null && array_diff($recipients, $sent) === []) {
            $this->markSent($rls, $report, $sent, true);
        }
    }

    /** @param  list<string>  $sent */
    private function markSent(TenantRls $rls, Report $report, array $sent, bool $finished): void
    {
        $rls->run([$this->tenantId], function () use ($report, $sent, $finished): void {
            $report->forceFill([
                'sent_to' => $sent,
                'sent_at' => $finished ? CarbonImmutable::now('UTC') : null,
            ])->save();
        });
    }
}
