<?php

namespace App\Extraction;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs extraction against the provider (docs/spec.md 8.2): submits a Message Batch, and when it has ended
 * validates every result. A result that is not valid JSON of the right shape, an errored, expired or
 * canceled request, and a request without any result get one new attempt, singly through the Messages API.
 * If that fails too the attempt has no result and the caller keeps the deterministic mentions.
 *
 * Failures are logged by custom id and error class only: neither prompts nor answers nor provider messages.
 */
final class ExtractionRunner
{
    public function __construct(private readonly ExtractionClient $client) {}

    /** @param  list<ExtractionRequest>  $requests */
    public function submit(array $requests): string
    {
        return $this->client->createBatch($requests);
    }

    public function ended(string $batchId): bool
    {
        return $this->client->batchEnded($batchId);
    }

    /**
     * @param  list<string>  $customIds  every request that was in the batch
     * @param  Closure(string): ?ExtractionRequest  $requestFor  rebuilds a request for the retry
     * @return array<string, ExtractionAttempt> by custom id
     */
    public function collect(string $batchId, array $customIds, Closure $requestFor): array
    {
        $wanted = array_fill_keys($customIds, true);
        $results = [];

        foreach ($this->client->batchResults($batchId) as $outcome) {
            if (! isset($wanted[$outcome->customId]) || isset($results[$outcome->customId])) {
                continue;
            }
            $result = $outcome->succeeded ? ExtractionResult::fromJson($outcome->text) : null;
            $results[$outcome->customId] = ['outcomes' => [$outcome], 'result' => $result];
        }

        $attempts = [];
        foreach ($customIds as $customId) {
            $first = $results[$customId] ?? ['outcomes' => [], 'result' => null];

            if ($first['result'] !== null) {
                $attempts[$customId] = new ExtractionAttempt($customId, $first['result'], $first['outcomes'], false);

                continue;
            }

            Log::info('Extraction batch result unusable, retrying once.', ['custom_id' => $customId]);
            $attempts[$customId] = $this->retry($customId, $first['outcomes'], $requestFor);
        }

        return $attempts;
    }

    /**
     * @param  list<ExtractionOutcome>  $outcomes
     * @param  Closure(string): ?ExtractionRequest  $requestFor
     */
    private function retry(string $customId, array $outcomes, Closure $requestFor): ExtractionAttempt
    {
        $request = $requestFor($customId);
        if ($request === null) {
            return new ExtractionAttempt($customId, null, $outcomes, false);
        }

        try {
            $retry = $this->client->message($request);
        } catch (Throwable $e) {
            Log::warning('Extraction retry failed.', ['custom_id' => $customId, 'error' => $e::class]);

            return new ExtractionAttempt($customId, null, $outcomes, true);
        }

        $outcomes[] = $retry;
        $result = $retry->succeeded ? ExtractionResult::fromJson($retry->text) : null;
        if ($result === null) {
            Log::warning('Extraction retry returned no usable result.', ['custom_id' => $customId]);
        }

        return new ExtractionAttempt($customId, $result, $outcomes, true);
    }
}
