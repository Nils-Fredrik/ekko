<?php

namespace App\Extraction;

/**
 * The provider calls model-based extraction needs (docs/spec.md 8.2): Message Batches for the bulk of the
 * answers and the plain Messages API for the single retry. {@see AnthropicExtractionClient} is the real
 * implementation; tests and the offline evaluation use {@see RecordedExtractionClient}.
 */
interface ExtractionClient
{
    /**
     * Submits the requests as one Message Batch and returns the provider's batch id.
     *
     * @param  list<ExtractionRequest>  $requests
     */
    public function createBatch(array $requests): string;

    /** Whether the batch has finished processing (all requests succeeded, errored, expired or were canceled). */
    public function batchEnded(string $batchId): bool;

    /**
     * Streams the results of an ended batch, in any order.
     *
     * @return iterable<ExtractionOutcome>
     */
    public function batchResults(string $batchId): iterable;

    /** One request through the plain Messages API (the single retry). */
    public function message(ExtractionRequest $request): ExtractionOutcome;
}
