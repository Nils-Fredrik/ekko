<?php

namespace App\Extraction;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Detection\DetectionCatalog;
use App\Detection\DeterministicDetector;
use App\Jobs\PollExtractionBatch;
use App\Jobs\SubmitExtractionBatch;
use App\Models\ExtractionJob;
use App\Models\Response;
use App\Models\Team;
use App\Work\WorkChain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Submits the next Message Batch of one tenant's collected, not yet extracted answers (docs/spec.md 8.2,
 * ADR 0011). Shared by {@see SubmitExtractionBatch} (the scheduled path) and the extraction work handler (T40-F3).
 * Runs in the tenant's context and row-level security transaction; a batch never mixes tenants.
 *
 * At most one batch per call, so a failing submit has nothing accepted to lose. A Postgres advisory lock per tenant,
 * held until the transaction ends, keeps two calls from overlapping: the second waits and then sees the batch row the
 * first wrote, instead of sending the same answers again.
 */
final class ExtractionSubmitter
{
    /** First key of the transaction-scoped advisory lock; the second is the tenant id. */
    private const LOCK_NAMESPACE = 20261115;

    public function __construct(
        private readonly ExtractionRunner $runner,
        private readonly DeterministicDetector $detector,
        private readonly ExtractionPromptBuilder $prompts,
        private readonly ExtractionWriter $writer,
        private readonly WorkChain $chain,
    ) {}

    /** Submits the next batch; true when more answers wait than fitted in it. */
    public function submitNext(int $tenantId): bool
    {
        $tenant = Team::query()->findOrFail($tenantId);
        // Expiry stops new runs. Answers from runs created before pitch_expires_at are still extracted,
        // including when they were collected after the pitch stopped (docs/pitch.md).
        $now = CarbonImmutable::now('UTC');
        $finishStarted = $tenant->kind === 'pitch' && ! $tenant->mayStartProjectWorkAt($now);
        if ($finishStarted && $tenant->pitch_expires_at === null) {
            return false;
        }
        DB::select('select pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $tenantId]);

        $size = max(1, (int) config('ekko.anthropic.extraction.batch_size'));
        $collected = function ($query) use ($tenant, $finishStarted): void {
            $query->where('status', 'collected');
            if ($finishStarted) {
                $query->where('created_at', '<', $tenant->pitch_expires_at);
            }
        };

        // An empty answer has nothing to analyse: no model call, the run is simply extracted.
        Response::query()
            ->whereHas('run', $collected)
            ->whereRaw("answer_text ~ '^\\s*$'")
            ->each(fn (Response $response) => $this->writer->markExtracted($response));

        $inFlight = [];
        foreach (ExtractionJob::query()->where('status', 'submitted')->get() as $job) {
            foreach ((array) $job->response_ids as $id) {
                $inFlight[] = (int) $id;
            }
        }

        // One more than a batch, to know whether another run is needed. The in-flight filter is in the query,
        // before the limit, so answers that are waiting for their results never crowd out new ones.
        $responses = Response::query()
            ->whereHas('run', $collected)
            ->whereRaw("answer_text !~ '^\\s*$'")
            ->whereNotIn('id', $inFlight)
            ->with('run.prompt.market')
            ->orderBy('id')
            ->limit($size + 1)
            ->get();

        $more = $responses->count() > $size;
        $chunk = $responses->take($size)->values();
        if ($chunk->isEmpty()) {
            return false;
        }

        $catalog = DetectionCatalog::load();
        foreach ($chunk as $response) {
            // The deterministic stage first: it recreates its rows, and the model stage builds on them.
            $this->detector->detect($response, $catalog);
        }

        $requests = $chunk->map(fn (Response $response): ExtractionRequest => $this->prompts->build(
            'r'.$response->id,
            (string) $response->answer_text,
            $response->run->prompt->market->language ?? $response->answer_language ?? 'und',
            $catalog->aliases,
            $this->writer->deterministicMentions($response),
            $response->annotationUrls(),
        ))->all();

        try {
            $batchId = $this->runner->submit($requests);
        } catch (Throwable $failure) {
            if (! WorkChain::enabled() || ! self::ambiguous($failure)) {
                throw $failure;
            }

            // The provider may have accepted the batch, and there is no way to ask which answers it holds. Sending
            // them again could pay twice, so they get the established terminal fallback instead: deterministic
            // results kept, llm_failed set, the run extracted (docs/spec.md 8.2, T40-F3).
            Log::error('extraction.submit_ambiguous', ['tenant_id' => $tenantId, 'responses' => $chunk->count(), 'error_type' => $failure::class]);
            $chunk->each(fn (Response $response) => $this->writer->abandon($response));

            return $more;
        }

        $job = ExtractionJob::query()->create([
            'provider_batch_id' => $batchId,
            'status' => 'submitted',
            'response_ids' => $chunk->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'submitted_at' => CarbonImmutable::now('UTC'),
        ]);

        if (WorkChain::enabled()) {
            // Status checks through a deferred work signal, so no worker waits for the batch (T40-F3).
            $this->chain->submitted($job);
        } else {
            PollExtractionBatch::dispatch($tenantId, $job->id)
                ->delay(now()->addSeconds((int) config('ekko.anthropic.extraction.poll_delay_seconds')));
        }

        return $more;
    }

    /**
     * Whether a failed submit may still have created the batch: a broken connection or timeout, a request timeout
     * or a server error. A rejection (4xx such as 400, 401, 413 or 429) created nothing and is safe to try again,
     * and so is 529 (overloaded: the API did not take the request) and a failure before the request went out.
     */
    public static function ambiguous(Throwable $failure): bool
    {
        if ($failure instanceof APIConnectionException) {
            return true;
        }

        return $failure instanceof APIStatusException && ($failure->status === null || $failure->status === 408 || ($failure->status >= 500 && $failure->status !== 529));
    }
}
