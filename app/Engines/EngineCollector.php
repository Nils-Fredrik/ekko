<?php

namespace App\Engines;

use App\Engines\Credentials\TenantApiKeys;
use App\Models\Run;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Usage\BillingAccount;
use App\Usage\MonthlyUsage;
use App\Usage\PlannedRun;
use App\Usage\QuotaGuard;
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
        private TenantApiKeys $tenantKeys,
        private MonthlyUsage $monthlyUsage,
        private QuotaGuard $quotaGuard,
    ) {}

    /** Returns null if the run was skipped because it could not fit the current quota or budget. */
    public function collect(int $runId, #[SensitiveParameter] ?string $apiKey = null): ?CollectedResponse
    {
        $tenantId = current_tenant_id();

        return DB::transaction(function () use ($runId, $apiKey, $tenantId): ?CollectedResponse {
            $tenant = Team::query()->lockForUpdate()->findOrFail($tenantId);
            $run = Run::query()->lockForUpdate()->findOrFail($runId);
            $existingResponse = $run->response()->first();

            if ($existingResponse !== null) {
                return new CollectedResponse($existingResponse, null);
            }

            if ($run->status === 'skipped_quota') {
                return null;
            }

            $engine = $run->engine()->first();
            if (! in_array($run->status, ['queued', 'running'], true) || ! $engine?->is_enabled) {
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
            $apiKey ??= $this->tenantKeys->forEngine($tenant, $engine)?->key;
            $billingAccount = $apiKey === null ? BillingAccount::Platform : BillingAccount::Byok;
            $usage = $this->monthlyUsage->snapshot($tenant, $occurredAt);
            $planned = new PlannedRun($run->id, $prompt->priority === 'core', $billingAccount, $price->price_per_call_usd);

            if (! $this->quotaGuard->allows($usage, $planned)) {
                $run->update(['status' => 'skipped_quota', 'finished_at' => $occurredAt]);

                return null;
            }

            if ($apiKey === null && in_array($engine->key, ['chatgpt_api', 'gemini_api'], true)) {
                throw new EngineCallFailed('tenant_api_key_missing');
            }

            $apiKey ??= config()->string("engines.platform_keys.{$engine->provider}", '');
            $result = $adapter->collect(new EngineRequest(
                promptText: $prompt->text,
                country: $market->country,
                language: $market->language,
                city: $market->location_label,
                runId: (string) $run->id,
                apiKey: $apiKey === '' ? null : $apiKey,
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
                'billed_to' => $billingAccount->value,
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
