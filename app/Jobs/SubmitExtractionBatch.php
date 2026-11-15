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
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Submits the collected, not yet extracted responses of one tenant to Anthropic Message Batches
 * (docs/spec.md 8.2, ADR 0011). A batch never mixes tenants: every batch is one {@see ExtractionJob} row of
 * the tenant, and its results are written back by {@see PollExtractionBatch} under that tenant's context
 * and row-level security. At most one submit job per tenant runs at a time.
 */
class SubmitExtractionBatch extends TenantJob implements ShouldBeUnique
{
    /** Responses read per run; the rest waits for the next run. */
    private const MAX_RESPONSES = 1000;

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
        $inFlight = [];
        foreach (ExtractionJob::query()->where('status', 'submitted')->get() as $job) {
            foreach ((array) $job->response_ids as $id) {
                $inFlight[(int) $id] = true;
            }
        }

        $responses = Response::query()
            ->whereHas('run', fn ($query) => $query->where('status', 'collected'))
            ->with('run.prompt.market')
            ->orderBy('id')
            ->limit(self::MAX_RESPONSES)
            ->get()
            ->reject(fn (Response $response): bool => isset($inFlight[$response->id]));

        $catalog = DetectionCatalog::load();
        $size = max(1, (int) config('ekko.anthropic.extraction.batch_size'));
        $ready = [];

        foreach ($responses as $response) {
            if (trim((string) $response->answer_text) === '') {
                $writer->markExtracted($response);

                continue;
            }

            // The deterministic stage first: it recreates its rows, and the model stage builds on them.
            $detector->detect($response, $catalog);
            $ready[] = $response;
        }

        foreach (array_chunk($ready, $size) as $chunk) {
            $requests = array_map(fn (Response $response): ExtractionRequest => $prompts->build(
                'r'.$response->id,
                (string) $response->answer_text,
                $response->run->prompt->market->language ?? $response->answer_language ?? 'und',
                $catalog->aliases,
                $writer->deterministicMentions($response),
            ), $chunk);

            $batchId = $runner->submit($requests);

            $job = ExtractionJob::query()->create([
                'provider_batch_id' => $batchId,
                'status' => 'submitted',
                'response_ids' => array_map(fn (Response $response): int => $response->id, $chunk),
                'submitted_at' => CarbonImmutable::now('UTC'),
            ]);

            PollExtractionBatch::dispatch($this->tenantId, $job->id)
                ->delay(now()->addSeconds((int) config('ekko.anthropic.extraction.poll_delay_seconds')));
        }
    }
}
