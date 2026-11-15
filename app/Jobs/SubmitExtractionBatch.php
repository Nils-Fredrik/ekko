<?php

namespace App\Jobs;

use App\Detection\DetectionCatalog;
use App\Detection\DeterministicDetector;
use App\Extraction\ExtractionPromptBuilder;
use App\Extraction\ExtractionRequest;
use App\Extraction\ExtractionRunner;
use App\Extraction\ExtractionWriter;
use App\Models\ExtractionJob;
use App\Models\Response;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\DB;

/**
 * Submits the collected, not yet extracted responses of one tenant to Anthropic Message Batches
 * (docs/spec.md 8.2, ADR 0011). A batch never mixes tenants: every batch is one {@see ExtractionJob} row of
 * the tenant, and its results are written back by {@see PollExtractionBatch} under that tenant's context
 * and row-level security.
 *
 * One run submits at most one batch and queues itself again when more answers wait. The whole run is one
 * transaction, so a failure after the provider accepted a batch would lose the batch's row; with one
 * batch per run, a failing submit has nothing accepted to lose. Only one submit job per tenant is queued
 * at a time, and the lock is released when the job starts, so it can queue its successor. A Postgres advisory
 * lock per tenant keeps two runs from overlapping once they have started.
 */
class SubmitExtractionBatch extends TenantJob implements ShouldBeUniqueUntilProcessing
{
    /** First key of the transaction-scoped advisory lock; the second is the tenant id. */
    private const LOCK_NAMESPACE = 20261115;

    /** Seconds a queued submit job holds the lock if it never starts. */
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'extraction-submit:'.$this->tenantId;
    }

    public function handle(
        ExtractionRunner $runner,
        DeterministicDetector $detector,
        ExtractionPromptBuilder $prompts,
        ExtractionWriter $writer,
    ): void {
        // The unique lock is released when the job starts, so a second run can start while this one still holds
        // its transaction open. This lock, per tenant and released at commit or rollback, makes that run wait and
        // then see the batch row this one wrote, instead of sending the same answers again.
        DB::select('select pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $this->tenantId]);

        $size = max(1, (int) config('ekko.anthropic.extraction.batch_size'));

        // An empty answer has nothing to analyse: no model call, the run is simply extracted.
        Response::query()
            ->whereHas('run', fn ($query) => $query->where('status', 'collected'))
            ->whereRaw("answer_text ~ '^\\s*$'")
            ->each(fn (Response $response) => $writer->markExtracted($response));

        $inFlight = [];
        foreach (ExtractionJob::query()->where('status', 'submitted')->get() as $job) {
            foreach ((array) $job->response_ids as $id) {
                $inFlight[] = (int) $id;
            }
        }

        // One more than a batch, to know whether another run is needed. The in-flight filter is in the query,
        // before the limit, so answers that are waiting for their results never crowd out new ones.
        $responses = Response::query()
            ->whereHas('run', fn ($query) => $query->where('status', 'collected'))
            ->whereRaw("answer_text !~ '^\\s*$'")
            ->whereNotIn('id', $inFlight)
            ->with('run.prompt.market')
            ->orderBy('id')
            ->limit($size + 1)
            ->get();

        $more = $responses->count() > $size;
        $chunk = $responses->take($size)->values();
        if ($chunk->isEmpty()) {
            return;
        }

        $catalog = DetectionCatalog::load();
        foreach ($chunk as $response) {
            // The deterministic stage first: it recreates its rows, and the model stage builds on them.
            $detector->detect($response, $catalog);
        }

        $requests = $chunk->map(fn (Response $response): ExtractionRequest => $prompts->build(
            'r'.$response->id,
            (string) $response->answer_text,
            $response->run->prompt->market->language ?? $response->answer_language ?? 'und',
            $catalog->aliases,
            $writer->deterministicMentions($response),
            $response->annotationUrls(),
        ))->all();

        $job = ExtractionJob::query()->create([
            'provider_batch_id' => $runner->submit($requests),
            'status' => 'submitted',
            'response_ids' => $chunk->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'submitted_at' => CarbonImmutable::now('UTC'),
        ]);

        PollExtractionBatch::dispatch($this->tenantId, $job->id)
            ->delay(now()->addSeconds((int) config('ekko.anthropic.extraction.poll_delay_seconds')));

        if ($more) {
            self::dispatch($this->tenantId)->afterCommit();
        }
    }
}
