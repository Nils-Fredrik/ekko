<?php

namespace App\Jobs;

use App\AnthropicBudget\Account;
use App\AnthropicBudget\CreditExhausted;
use App\Extraction\ExtractionSubmitter;
use App\Models\ExtractionJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * Submits the collected, not yet extracted responses of one tenant to Anthropic Message Batches
 * (docs/spec.md 8.2, ADR 0011). A batch never mixes tenants: every batch is one {@see ExtractionJob} row of
 * the tenant, and its results are written back by {@see PollExtractionBatch} under that tenant's context
 * and row-level security.
 *
 * One run submits at most one batch ({@see ExtractionSubmitter}) and queues itself again when more answers wait.
 * The whole run is one transaction, so a failure after the provider accepted a batch would lose the batch's row;
 * with one batch per run, a failing submit has nothing accepted to lose. Only one submit job per tenant is queued
 * at a time, and the lock is released when the job starts, so it can queue its successor. The submitter's advisory
 * lock per tenant keeps two runs from overlapping once they have started.
 */
class SubmitExtractionBatch extends TenantJob implements ShouldBeUniqueUntilProcessing
{
    /** Seconds a queued submit job holds the lock if it never starts. */
    public int $uniqueFor = 3600;

    public function failed(?\Throwable $failure): void
    {
        if ($failure instanceof CreditExhausted) {
            app(Account::class)->markExhausted();
        }
    }

    public function uniqueId(): string
    {
        return 'extraction-submit:'.$this->tenantId;
    }

    public function handle(ExtractionSubmitter $submitter): void
    {
        if ($submitter->submitNext($this->tenantId)) {
            self::dispatch($this->tenantId)->afterCommit();
        }
    }
}
