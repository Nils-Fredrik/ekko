<?php

namespace Tests\Support\Extraction;

use App\Extraction\ExtractionClient;
use App\Extraction\ExtractionOutcome;
use App\Extraction\ExtractionRequest;
use App\Extraction\RecordedExtractionClient;
use Closure;

/**
 * Answers from a closure that maps a custom id to a recorded entry ({batch, retry}), and keeps what was sent
 * so a test can see exactly which answers went to the provider.
 */
final class FakeExtractionClient implements ExtractionClient
{
    /** @var list<list<string>> custom ids per submitted batch */
    public array $batches = [];

    /** @var list<ExtractionRequest> */
    public array $requests = [];

    /** @var list<string> custom ids sent through the Messages API */
    public array $retries = [];

    private RecordedExtractionClient $recorded;

    /** @param  Closure(string): array<string, mixed>  $entryFor */
    public function __construct(private readonly Closure $entryFor, private readonly bool $ended = true) {}

    public function createBatch(array $requests): string
    {
        $outputs = [];
        foreach ($requests as $request) {
            $this->requests[] = $request;
            $outputs[$request->customId] = ($this->entryFor)($request->customId);
        }
        $this->recorded = new RecordedExtractionClient($outputs);
        $this->batches[] = array_map(fn (ExtractionRequest $request): string => $request->customId, $requests);

        return 'batch-'.count($this->batches);
    }

    public function batchEnded(string $batchId): bool
    {
        return $this->ended;
    }

    public function batchResults(string $batchId): iterable
    {
        $id = $this->recorded->createBatch(array_map(fn (string $id): ExtractionRequest => new ExtractionRequest($id, '', ''), $this->batches[(int) substr($batchId, 6) - 1]));

        return $this->recorded->batchResults($id);
    }

    public function message(ExtractionRequest $request): ExtractionOutcome
    {
        $this->retries[] = $request->customId;

        return $this->recorded->message($request);
    }
}
