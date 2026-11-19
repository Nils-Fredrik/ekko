<?php

namespace App\Engines;

use App\AnthropicBudget\Account;
use App\AnthropicBudget\Estimator;
use App\Engines\Credentials\TenantApiKeys;
use App\Models\Run;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Tenancy\Rls\TenantRls;
use App\Usage\BillingAccount;
use App\Usage\MonthlyUsage;
use App\Usage\PlannedRun;
use App\Usage\QuotaGuard;
use App\Usage\RunReservations;
use App\Work\WorkChain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;
use Throwable;

final readonly class EngineCollector
{
    public function __construct(
        private EngineRegistry $registry,
        private EnginePricing $pricing,
        private RawResponseStore $rawResponses,
        private TenantApiKeys $tenantKeys,
        private MonthlyUsage $monthlyUsage,
        private QuotaGuard $quotaGuard,
        private RunReservations $reservations,
        private TenantRls $rls,
    ) {}

    /**
     * Requires tenant context and manages its own RLS transactions around admission and settlement.
     * Returns null if the run was skipped because it could not fit the current quota or budget.
     */
    public function collect(int $runId, #[SensitiveParameter] ?string $apiKey = null): ?CollectedResponse
    {
        $tenantId = current_tenant_id();

        if (DB::transactionLevel() > 0 && $this->rls->currentSettings()['tenant_ids'] !== '') {
            throw new LogicException('Engine collection requires its own tenant transactions.');
        }

        $sessionId = null;

        try {
            $reservation = $this->rls->run([$tenantId], function () use ($tenantId, $runId, &$sessionId, $apiKey) {
                $tenant = Team::query()->lock('for no key update')->findOrFail($tenantId);
                $run = Run::query()->lockForUpdate()->findOrFail($runId);
                $response = $run->response()->first();

                if ($response !== null) {
                    return new CollectedResponse($response, null);
                }

                if ($run->status === 'skipped_quota') {
                    return null;
                }

                $occurredAt = CarbonImmutable::now('UTC');
                $sessionId = $this->reservations->lock($run, $occurredAt);

                // An expired attempt cannot reserve capacity alongside its replacement.
                if ($run->quota_reserved_at !== null) {
                    $this->reservations->release($run);
                }

                return $this->reserve($tenant, $run, $occurredAt, $apiKey);
            });

            if (! $reservation instanceof ReservedEngineRun) {
                return $reservation;
            }

            try {
                $result = $reservation->adapter->collect($reservation->request);
                $collectedAt = CarbonImmutable::now('UTC');
                // Commit known billable usage before storage: a storage failure does not undo the provider call.
                $this->rls->run([$tenantId], fn () => $this->recordUsage($runId, $reservation, $result));
                $rawUri = $this->rawResponses->put($tenantId, $runId, $collectedAt, $result->raw);

                return $this->rls->run(
                    [$tenantId],
                    fn () => $this->settle($runId, $reservation, $result, $collectedAt, $rawUri),
                );
            } catch (Throwable $failure) {
                if ($failure instanceof EngineCallFailed && (preg_match('/^anthropic_http_[45][0-9]{2}$/', $failure->errorCode) || in_array($failure->errorCode, ['anthropic_credits_exhausted', 'anthropic_missing_api_key'], true))) {
                    $this->rls->run([$tenantId], function () use ($runId): void {
                        $attempt = Run::query()->whereKey($runId)->value('attempts');
                        app(Account::class)->settle('run:'.$runId.':'.$attempt);
                    });
                }
                try {
                    $this->rls->run([$tenantId], fn () => $this->reservations->releaseIfOwned($runId, $reservation->token));
                } catch (Throwable $releaseFailure) {
                    report($releaseFailure);
                }

                throw $failure;
            }
        } finally {
            if ($sessionId !== null) {
                try {
                    $this->reservations->unlock($runId, $sessionId);
                } catch (Throwable $unlockFailure) {
                    DB::disconnect();
                    report($unlockFailure);
                }
            }
        }
    }

    private function reserve(
        Team $tenant,
        Run $run,
        CarbonImmutable $occurredAt,
        #[SensitiveParameter] ?string $apiKey,
    ): ?ReservedEngineRun {
        if ($tenant->kind === 'pitch' && ! $tenant->mayCollectAt($occurredAt)) {
            throw new EngineCallFailed('pitch_expired');
        }
        $engine = $run->engine()->first();
        if (! in_array($run->status, ['queued', 'running'], true) || ! $engine?->isAvailable()) {
            throw new LogicException('This run cannot collect an engine response.');
        }

        $prompt = $run->prompt()->firstOrFail();
        $market = $prompt->market()->firstOrFail();
        $adapter = $this->registry->resolve($run->engine_key);

        if ($adapter instanceof QueuedEngineAdapter || ! $adapter->supports($market)) {
            throw new LogicException('This engine cannot collect synchronously for the market.');
        }

        $price = $this->pricing->effectiveAt($run->engine_key, $occurredAt);
        $apiKey ??= $this->tenantKeys->forEngine($tenant, $engine)?->key;
        $billing = $apiKey === null ? BillingAccount::Platform : BillingAccount::Byok;
        $usage = $this->monthlyUsage->forAdmission($tenant, $occurredAt);
        $planned = new PlannedRun($run->id, $prompt->priority === 'core', $billing, $price->price_per_call_usd);

        $budgetAllowed = true;
        if ($billing === BillingAccount::Platform && in_array($run->engine_key, ['claude_haiku', 'claude_sonnet'], true)) {
            $budgetAllowed = app(Account::class)->reserve('run:'.$run->id.':'.($run->attempts + 1), app(Estimator::class)->engine($run->engine_key, $occurredAt, 4096), $run->engine_key === 'claude_haiku' && $prompt->priority === 'core');
        }
        if (! $budgetAllowed || ! $this->quotaGuard->allows($usage, $planned)) {
            if ($budgetAllowed) {
                app(Account::class)->settle('run:'.$run->id.':'.($run->attempts + 1));
            }
            $run->update(['status' => 'skipped_quota', 'finished_at' => $occurredAt]);
            app(WorkChain::class)->ended($run);

            return null;
        }

        $run->update(['status' => 'running', 'attempts' => $run->attempts + 1, 'started_at' => $run->started_at ?? $occurredAt]);

        return new ReservedEngineRun(
            token: $this->reservations->reserve($run, $planned, $occurredAt),
            occurredAt: $occurredAt,
            billingAccount: $billing,
            price: $price,
            adapter: $adapter,
            request: new EngineRequest(
                promptText: $prompt->text,
                country: $market->country,
                language: $market->language,
                city: $market->location_label,
                runId: (string) $run->id,
                apiKey: $apiKey,
            ),
        );
    }

    private function recordUsage(int $runId, ReservedEngineRun $reservation, EngineResult $result): void
    {
        $run = Run::query()->lockForUpdate()->findOrFail($runId);
        if ($run->quota_reservation_token !== $reservation->token) {
            throw new EngineCallFailed('run_reservation_lost');
        }
        UsageEvent::query()->create([
            'collection_attempt_token' => $reservation->token,
            'run_id' => $run->id, 'engine_key' => $run->engine_key, 'kind' => 'engine_call',
            'occurred_at' => $reservation->occurredAt, 'calls' => 1,
            'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens,
            'search_calls' => $result->searchCalls,
            'cost_usd' => $this->pricing->cost($reservation->price, $result),
            'billed_to' => $reservation->billingAccount->value,
        ]);
        app(Account::class)->settle('run:'.$run->id.':'.$run->attempts);
    }

    private function settle(
        int $runId,
        ReservedEngineRun $reservation,
        EngineResult $result,
        CarbonImmutable $collectedAt,
        string $rawUri,
    ): CollectedResponse {
        $run = Run::query()->lockForUpdate()->findOrFail($runId);
        if ($run->quota_reservation_token !== $reservation->token) {
            throw new EngineCallFailed('run_reservation_lost');
        }

        $payload = [
            'engine_key' => $run->engine_key,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'search_calls' => $result->searchCalls,
            'cost_usd' => $this->pricing->cost($reservation->price, $result),
        ];

        $response = $run->response()->create($payload + [
            'answer_text' => $result->answerText,
            'model_id_reported' => $result->modelIdReported,
            'raw_uri' => $rawUri,
            'collected_at' => $collectedAt,
        ]);

        app(ResponseAnnotations::class)->store($response, $result);

        $run->fill([
            'status' => 'collected',
            'started_at' => $run->started_at ?? $reservation->occurredAt,
            'finished_at' => $collectedAt,
            'error_code' => null,
        ]);
        $this->reservations->release($run);
        // In the same transaction as the answer: its extraction is registered exactly when it commits (T40-F3).
        app(WorkChain::class)->collected($run);

        return new CollectedResponse($response, $result);
    }
}
