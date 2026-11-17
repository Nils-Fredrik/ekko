<?php

namespace App\Jobs;

use App\Detection\DetectionCatalog;
use App\Detection\DeterministicDetector;
use App\Extraction\ExtractionAssembler;
use App\Extraction\ExtractionPromptBuilder;
use App\Extraction\ExtractionRequest;
use App\Extraction\ExtractionRunner;
use App\Extraction\ExtractionWriter;
use App\Models\ExtractionJob;
use App\Models\Response;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Waits for one tenant's Message Batch and writes its results (docs/spec.md 8.2, ADR 0011): validates each
 * result, retries a bad one once through the Messages API, combines it with the deterministic mentions,
 * stores mentions, citations and answer type, and logs the usage. A response that fails twice keeps its
 * deterministic results and is flagged llm_failed. Safe to run again: finished responses are skipped.
 */
class PollExtractionBatch extends TenantJob
{
    /** Retried by being released until the batch has ended or has expired (batches live 24 hours). */
    public int $tries = 0;

    /**
     * Seconds between retries after an exception (not after a release while the batch is running).
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(int $tenantId, public readonly int $extractionJobId)
    {
        parent::__construct($tenantId);
        $this->afterCommit();
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(25);
    }

    /**
     * The job gave up (an exception it could not recover from, or the batch never finished). The spec says what
     * then happens (8.2): the deterministic results are kept and the answers are flagged llm_failed. Running
     * outside the job middleware, it sets up the tenant context and row-level security itself.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Extraction batch given up.', ['extraction_job_id' => $this->extractionJobId, 'error' => $exception::class]);

        app(TenantContext::class)->run($this->tenantId, fn () => app(TenantRls::class)->run([$this->tenantId], function (): void {
            $job = ExtractionJob::query()->find($this->extractionJobId);
            if ($job === null || $job->status !== 'submitted') {
                return;
            }

            $writer = app(ExtractionWriter::class);
            Response::query()->whereIn('id', $job->response_ids)->whereHas('run', fn ($query) => $query->where('status', 'collected'))
                ->each(fn (Response $response) => $writer->abandon($response));
            $job->update(['status' => 'failed', 'completed_at' => CarbonImmutable::now('UTC')]);
        }));
    }

    public function handle(
        ExtractionRunner $runner,
        DeterministicDetector $detector,
        ExtractionPromptBuilder $prompts,
        ExtractionAssembler $assembler,
        ExtractionWriter $writer,
    ): void {
        $job = ExtractionJob::query()->findOrFail($this->extractionJobId);
        if ($job->status !== 'submitted') {
            // Finished, or given up (failed): never written twice and never turned from failed to completed.
            return;
        }

        if (! $runner->ended($job->provider_batch_id)) {
            $this->release((int) config('ekko.anthropic.extraction.poll_delay_seconds'));

            return;
        }

        $responses = Response::query()
            ->whereIn('id', $job->response_ids)
            ->whereHas('run', fn ($query) => $query->where('status', 'collected'))
            ->with('run.prompt.market')
            ->orderBy('id')
            ->get()->keyBy('id');

        $catalog = DetectionCatalog::load();
        $deterministic = [];
        $sources = [];
        foreach ($responses as $id => $response) {
            $detector->detect($response, $catalog);
            $deterministic[$id] = $writer->deterministicMentions($response);
            $sources[$id] = $response->annotationUrls();
        }

        $requestFor = function (string $customId) use ($responses, $deterministic, $sources, $catalog, $prompts): ?ExtractionRequest {
            $response = $responses[(int) substr($customId, 1)] ?? null;

            return $response === null ? null : $prompts->build(
                $customId,
                (string) $response->answer_text,
                $response->run->prompt->market->language ?? $response->answer_language ?? 'und',
                $catalog->aliases,
                $deterministic[$response->id],
                $sources[$response->id],
            );
        };

        $attempts = $runner->collect(
            $job->provider_batch_id,
            array_map(fn (int $id): string => 'r'.$id, $responses->keys()->all()),
            $requestFor,
        );

        foreach ($attempts as $customId => $attempt) {
            $response = $responses[(int) substr($customId, 1)];
            $assembled = $assembler->assemble((string) $response->answer_text, $deterministic[$response->id], $attempt->result, $catalog->aliases);

            $writer->write($response, $assembled, $catalog);
            $writer->recordUsage($response, $attempt);
        }

        $job->update(['status' => 'completed', 'completed_at' => CarbonImmutable::now('UTC')]);
    }
}
