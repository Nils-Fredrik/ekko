<?php

namespace Tests\Support\Work;

use App\Extraction\ExtractionClient;
use App\Extraction\ExtractionOutcome;
use App\Extraction\ExtractionRequest;
use Tests\Support\Extraction\FakeExtractionClient;
use Throwable;

/** An extraction client that can be told to fail at submission, and counts its status calls. */
final class ChainExtractionClient implements ExtractionClient
{
    public int $statusChecks = 0;

    public bool $ended = false;

    public ?Throwable $failNext = null;

    public function __construct(public FakeExtractionClient $inner) {}

    public function createBatch(array $requests): string
    {
        if ($this->failNext !== null) {
            $failure = $this->failNext;
            $this->failNext = null;

            throw $failure;
        }

        return $this->inner->createBatch($requests);
    }

    public function batchEnded(string $batchId): bool
    {
        $this->statusChecks++;

        return $this->ended;
    }

    public function batchResults(string $batchId): iterable
    {
        return $this->inner->batchResults($batchId);
    }

    public function message(ExtractionRequest $request): ExtractionOutcome
    {
        return $this->inner->message($request);
    }
}
