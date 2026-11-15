<?php

namespace App\Engines;

use App\Models\Run;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;

final readonly class EngineCollector
{
    public function __construct(
        private EngineRegistry $registry,
        private EnginePricing $pricing,
        private RawResponseStore $rawResponses,
    ) {}

    public function collect(int $runId, #[SensitiveParameter] ?string $apiKey = null): CollectedResponse
    {
        $tenantId = current_tenant_id();

        return DB::transaction(function () use ($runId, $apiKey, $tenantId): CollectedResponse {
            $run = Run::query()->lockForUpdate()->findOrFail($runId);
            $existingResponse = $run->response()->first();

            if ($existingResponse !== null) {
                return new CollectedResponse($existingResponse, null);
            }

            if (! in_array($run->status, ['queued', 'running'], true) || ! $run->engine?->is_enabled) {
                throw new LogicException('This run cannot collect an engine response.');
            }

            $prompt = $run->prompt()->firstOrFail();
            $market = $prompt->market()->firstOrFail();
            $adapter = $this->registry->resolve($run->engine_key);

            if ($adapter instanceof QueuedEngineAdapter || ! $adapter->supports($market)) {
                throw new LogicException('This engine cannot collect synchronously for the market.');
            }

            $occurredAt = CarbonImmutable::now('UTC');
            $price = $this->pricing->effectiveAt($run->engine_key, $occurredAt);
            $result = $adapter->collect(new EngineRequest(
                promptText: $prompt->text,
                country: $market->country,
                language: $market->language,
                city: $market->location_label,
                runId: (string) $run->id,
                apiKey: $apiKey,
            ));

            $collectedAt = CarbonImmutable::now('UTC');
            $payload = [
                'engine_key' => $run->engine_key,
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'search_calls' => $result->searchCalls,
                'cost_usd' => $this->pricing->cost($price, $result),
            ];

            $rawUri = $this->rawResponses->put($tenantId, $run->id, $collectedAt, $result->raw);
            $response = $run->response()->create($payload + [
                'answer_text' => $result->answerText,
                'model_id_reported' => $result->modelIdReported,
                'raw_uri' => $rawUri,
                'collected_at' => $collectedAt,
            ]);

            UsageEvent::query()->create($payload + [
                'run_id' => $run->id,
                'kind' => 'engine_call',
                'occurred_at' => $occurredAt,
                'calls' => 1,
                'billed_to' => $apiKey === null ? 'platform' : 'byok',
            ]);

            $run->update([
                'status' => 'collected',
                'started_at' => $run->started_at ?? $occurredAt,
                'finished_at' => $collectedAt,
                'error_code' => null,
            ]);

            return new CollectedResponse($response, $result);
        });
    }
}
