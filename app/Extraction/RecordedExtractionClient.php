<?php

namespace App\Extraction;

use InvalidArgumentException;

/**
 * Replays recorded model output instead of calling the API: for the offline evaluation (`--recorded`) and for
 * tests. The file maps a custom id to what the batch returned and, optionally, to what the single retry
 * returned:
 *
 *   {"outputs": {"nb-01": {"batch": {"text": {...}, "input_tokens": 700, "output_tokens": 90},
 *                          "retry": {"error": "errored"}}}}
 *
 * "text" is either a JSON object (written back as JSON) or a string used as it is, so invalid JSON can be
 * recorded. {"error": "<type>"} stands for a request that errored or expired. A custom id with no "batch"
 * entry gets no batch result; one with no "retry" entry fails its retry.
 */
final class RecordedExtractionClient implements ExtractionClient
{
    /** @var array<string, array<string, mixed>> */
    private array $submitted = [];

    /** @param  array<string, array{batch?: array<string, mixed>, retry?: array<string, mixed>|null}>  $outputs */
    public function __construct(private readonly array $outputs) {}

    public static function fromFile(string $path): self
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ! is_array($data['outputs'] ?? null)) {
            throw new InvalidArgumentException('The recorded output file is missing or invalid.');
        }

        return new self($data['outputs']);
    }

    public function createBatch(array $requests): string
    {
        $id = 'recorded-'.(count($this->submitted) + 1);
        $this->submitted[$id] = array_map(fn (ExtractionRequest $request): string => $request->customId, $requests);

        return $id;
    }

    public function batchEnded(string $batchId): bool
    {
        return isset($this->submitted[$batchId]);
    }

    public function batchResults(string $batchId): iterable
    {
        foreach ($this->submitted[$batchId] ?? [] as $customId) {
            $entry = $this->outputs[$customId]['batch'] ?? null;
            if (is_array($entry)) {
                yield $this->outcome($customId, $entry, true);
            }
        }
    }

    public function message(ExtractionRequest $request): ExtractionOutcome
    {
        $entry = $this->outputs[$request->customId]['retry'] ?? null;

        return is_array($entry)
            ? $this->outcome($request->customId, $entry, false)
            : new ExtractionOutcome($request->customId, false, null, 0, 0, false, 'not_recorded');
    }

    /** @param  array<string, mixed>  $entry */
    private function outcome(string $customId, array $entry, bool $viaBatch): ExtractionOutcome
    {
        if (isset($entry['error'])) {
            return new ExtractionOutcome($customId, false, null, 0, 0, $viaBatch, (string) $entry['error']);
        }

        $text = $entry['text'] ?? null;
        if (is_array($text)) {
            $text = json_encode($text, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return new ExtractionOutcome(
            $customId, true, is_string($text) ? $text : null,
            (int) ($entry['input_tokens'] ?? 0), (int) ($entry['output_tokens'] ?? 0), $viaBatch,
        );
    }
}
