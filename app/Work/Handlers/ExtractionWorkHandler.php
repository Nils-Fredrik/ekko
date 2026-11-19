<?php

namespace App\Work\Handlers;

use App\Extraction\ExtractionRunner;
use App\Extraction\ExtractionSubmitter;
use App\Jobs\PollExtractionBatch;
use App\Models\ExtractionJob;
use App\Work\WorkClaim;
use App\Work\WorkHandler;
use App\Work\WorkKind;
use App\Work\WorkOutcome;
use App\Work\WorkScope;
use App\Work\WorkSignals;
use Carbon\CarbonImmutable;

/**
 * Extraction work of one tenant (T40-F3, ADR 0032):
 *
 * - run_batch:<id> or tenant: submits the next Message Batch of collected answers with the same
 *   {@see ExtractionSubmitter} as SubmitExtractionBatch. When more answers wait, the scope is signalled again, so the
 *   next batch follows as soon as this one is acknowledged.
 * - extraction_job:<id>: one status check of a submitted batch. A batch that has ended starts the writing of its
 *   results ({@see PollExtractionBatch}, after commit); a batch still running is deferred with a growing interval,
 *   so no worker waits for it and no new batch is ever submitted to check on it. A finished or given up job ends the
 *   checks; one that never ends is given up after ekko.work.extraction.status_give_up_hours.
 */
final readonly class ExtractionWorkHandler implements WorkHandler
{
    public function __construct(
        private ExtractionSubmitter $submitter,
        private ExtractionRunner $runner,
        private WorkSignals $signals,
    ) {}

    public function handle(WorkClaim $claim): WorkOutcome
    {
        $jobId = WorkScope::id($claim->scope, 'extraction_job');
        if ($jobId !== null) {
            return $this->check($claim, $jobId);
        }

        if ($this->submitter->submitNext($claim->tenantId)) {
            // The row is leased by this claim: the signal only raises its version, and the acknowledgement wakes it.
            $this->signals->signal(WorkKind::Extraction, $claim->scope);
        }

        return WorkOutcome::done();
    }

    private function check(WorkClaim $claim, int $jobId): WorkOutcome
    {
        $job = ExtractionJob::query()->lockForUpdate()->find($jobId);
        if ($job === null || $job->status !== 'submitted') {
            return WorkOutcome::done();
        }

        if ($this->runner->ended($job->provider_batch_id)) {
            // Queued after commit, together with the acknowledgement.
            PollExtractionBatch::dispatch($claim->tenantId, $job->id);

            return WorkOutcome::done();
        }

        $now = CarbonImmutable::now('UTC');
        $submitted = CarbonImmutable::parse($job->submitted_at ?? $job->created_at)->utc();
        if ($submitted->addHours(max(1, (int) config('ekko.work.extraction.status_give_up_hours')))->lessThanOrEqualTo($now)) {
            PollExtractionBatch::giveUp($job);

            return WorkOutcome::done();
        }

        return WorkOutcome::retryAt($now->addSeconds(self::nextCheckSeconds($submitted, $now)));
    }

    /**
     * Seconds to the next status check: the batch's age so far, so the interval doubles, between status_first_seconds
     * and status_max_seconds, plus up to status_jitter_seconds.
     */
    public static function nextCheckSeconds(CarbonImmutable $submitted, CarbonImmutable $now): int
    {
        $first = max(1, (int) config('ekko.work.extraction.status_first_seconds'));
        $cap = max($first, (int) config('ekko.work.extraction.status_max_seconds'));
        $jitter = max(0, (int) config('ekko.work.extraction.status_jitter_seconds'));
        $age = max(0, (int) $submitted->diffInSeconds($now));

        return min($cap, max($first, $age)) + ($jitter > 0 ? random_int(0, $jitter) : 0);
    }
}
