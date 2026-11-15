<?php

namespace App\Extraction;

/**
 * Wraps a client and keeps what the model returned, in the format {@see RecordedExtractionClient} replays.
 * The evaluation command uses it for `--record`, so one run with a real key can be replayed offline.
 * The recording holds model output for the synthetic gold set only, never real answers.
 */
final class RecordingExtractionClient implements ExtractionClient
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $outputs = [];

    public function __construct(private readonly ExtractionClient $inner) {}

    public function createBatch(array $requests): string
    {
        return $this->inner->createBatch($requests);
    }

    public function batchEnded(string $batchId): bool
    {
        return $this->inner->batchEnded($batchId);
    }

    public function batchResults(string $batchId): iterable
    {
        foreach ($this->inner->batchResults($batchId) as $outcome) {
            $this->outputs[$outcome->customId]['batch'] = $this->entry($outcome);

            yield $outcome;
        }
    }

    public function message(ExtractionRequest $request): ExtractionOutcome
    {
        $outcome = $this->inner->message($request);
        $this->outputs[$request->customId]['retry'] = $this->entry($outcome);

        return $outcome;
    }

    public function writeTo(string $path): void
    {
        ksort($this->outputs);
        file_put_contents($path, json_encode(['outputs' => $this->outputs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array<string, mixed> */
    private function entry(ExtractionOutcome $outcome): array
    {
        if (! $outcome->succeeded) {
            return ['error' => $outcome->errorType ?? 'errored', 'input_tokens' => $outcome->inputTokens, 'output_tokens' => $outcome->outputTokens];
        }

        $decoded = json_decode((string) $outcome->text, true);

        return ['text' => is_array($decoded) ? $decoded : $outcome->text, 'input_tokens' => $outcome->inputTokens, 'output_tokens' => $outcome->outputTokens];
    }
}
