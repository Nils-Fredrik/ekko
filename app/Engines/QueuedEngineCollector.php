<?php

namespace App\Engines;

use App\Engines\Adapters\DataForSeoQueuedAdapter;
use App\Engines\Credentials\TenantApiKeys;
use App\Models\EnginePrice;
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
use Throwable;

/** External requests run outside the tenant transaction, under a per-run PostgreSQL lease. */
final class QueuedEngineCollector
{
    public function __construct(private EngineRegistry $registry, private EnginePricing $pricing, private TenantApiKeys $keys, private TenantRls $rls, private RunReservations $reservations, private MonthlyUsage $usage, private QuotaGuard $quota, private RawResponseStore $raw, private ResponseAnnotations $annotations) {}

    public function collect(int $runId): void
    {
        $tenantId = current_tenant_id();
        if (DB::transactionLevel() > 0 && $this->rls->currentSettings()['tenant_ids'] !== '') {
            throw new LogicException('Queued collection requires its own tenant transactions.');
        }
        $session = null;
        try {
            $run = $this->rls->run([$tenantId], function () use ($runId, &$session): ?Run {
                $run = Run::query()->with('prompt.market', 'engine')->lockForUpdate()->findOrFail($runId);
                if (! in_array($run->status, ['queued', 'submitted'], true)) {
                    return null;
                }
                $session = $this->reservations->lock($run, CarbonImmutable::now('UTC'));
                $this->reservations->release($run);

                return $run;
            });
            if ($run === null) {
                return;
            }
            if (! $run->engine->isAvailable()) {
                throw new EngineCallFailed('engine_disabled');
            }
            $adapter = $this->registry->resolve($run->engine_key);
            if (! $adapter instanceof QueuedEngineAdapter || ! $adapter->supports($run->prompt->market)) {
                throw new EngineCallFailed('unsupported_queued_engine');
            }
            if ($run->provider_task_id !== null) {
                $result = null;
                if (! $adapter instanceof DataForSeoQueuedAdapter || in_array($run->provider_task_id, $adapter->tasksReady($run->provider_api_key), true)) {
                    $result = $adapter instanceof DataForSeoQueuedAdapter
                        ? $adapter->fetch($run->provider_task_id, $run->provider_api_key)
                        : $adapter->fetch($run->provider_task_id);
                }
                if ($result !== null) {
                    $at = CarbonImmutable::now('UTC');
                    $uri = $this->raw->put($tenantId, $runId, $at, $result->raw);
                    $this->rls->run([$tenantId], function () use ($runId, $result, $uri, $at): void {
                        $run = Run::query()->lockForUpdate()->findOrFail($runId);
                        $price = EnginePrice::query()->findOrFail($run->provider_price_id);
                        $response = $run->response()->create([
                            'engine_key' => $run->engine_key, 'answer_text' => $result->answerText,
                            'model_id_reported' => $result->modelIdReported, 'raw_uri' => $uri, 'collected_at' => $at,
                            'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens,
                            'search_calls' => $result->searchCalls, 'cost_usd' => $this->pricing->cost($price, $result),
                        ]);
                        $this->annotations->store($response, $result);
                        $run->forceFill(['status' => 'collected', 'finished_at' => $at, 'error_code' => null, 'enqueued_at' => null, 'next_poll_at' => null, 'provider_api_key' => null])->save();
                        app(WorkChain::class)->collected($run);
                    });

                    return;
                }
                if ($run->provider_submitted_at?->addHours(6)->isFuture()) {
                    $this->wait($runId);

                    return;
                }
                $tenant = Team::query()->findOrFail($tenantId);
                if ($tenant->kind === 'pitch' && ! $tenant->mayCollectAt(CarbonImmutable::now('UTC'))) {
                    // No paid resubmission after expiry; give the original task the normal two-attempt window.
                    if ($run->provider_submitted_at?->addHours(12)->isFuture()) {
                        $this->wait($runId);

                        return;
                    }
                    throw new EngineCallFailed('provider_task_timeout');
                }
                if ($run->provider_submissions >= 2) {
                    throw new EngineCallFailed('provider_task_timeout');
                }
            }
            $reservation = $this->rls->run([$tenantId], function () use ($tenantId, $runId, $adapter): ?ReservedEngineRun {
                $tenant = Team::query()->lock('for no key update')->findOrFail($tenantId);
                $run = Run::query()->with('prompt.market', 'engine')->lockForUpdate()->findOrFail($runId);
                if (! $run->engine->isAvailable()) {
                    throw new EngineCallFailed('engine_disabled');
                }
                $at = CarbonImmutable::now('UTC');
                if ($tenant->kind === 'pitch' && ! $tenant->mayCollectAt($at)) {
                    throw new EngineCallFailed('pitch_expired');
                }
                if ($run->prompt->market->brand()->sharedLock()->value('status') === 'archived') {
                    throw new EngineCallFailed('brand_archived');
                }
                // A resubmission uses the same provider account as the original task, even after key rotation. Only a
                // tenant's own key (BYOK) is stored on the run; a null key means the platform account, read from config
                // at each call, so the platform credentials never land in the runs table (T39-F10).
                $key = $run->provider_submissions > 0 ? $run->provider_api_key : $this->keys->forEngine($tenant, $run->engine)?->key;
                $billing = $run->provider_submissions > 0
                    ? BillingAccount::from($run->provider_billed_to)
                    : ($key === null ? BillingAccount::Platform : BillingAccount::Byok);
                $price = $this->pricing->effectiveAt($run->engine_key, $at);
                $planned = new PlannedRun($runId, $run->prompt->priority === 'core', $billing, $price->price_per_call_usd);
                if (! $this->quota->allows($this->usage->forAdmission($tenant, $at), $planned)) {
                    $run->forceFill(['status' => 'skipped_quota', 'finished_at' => $at, 'enqueued_at' => null, 'provider_api_key' => null])->save();
                    app(WorkChain::class)->ended($run);

                    return null;
                }
                $run->update(['attempts' => $run->attempts + 1, 'started_at' => $run->started_at ?? $at]);

                return new ReservedEngineRun($this->reservations->reserve($run, $planned, $at), $at, $billing, $price, $adapter,
                    new EngineRequest($run->prompt->text, $run->prompt->market->country, $run->prompt->market->language, $run->prompt->market->location_label, (string) $runId, $key));
            });
            if ($reservation === null) {
                return;
            }
            try {
                $providerId = $adapter->submit($reservation->request);
                $this->rls->run([$tenantId], function () use ($runId, $providerId, $reservation): void {
                    $run = Run::query()->lockForUpdate()->findOrFail($runId);
                    if ($run->quota_reservation_token !== $reservation->token) {
                        throw new EngineCallFailed('run_reservation_lost');
                    }
                    UsageEvent::query()->create([
                        'collection_attempt_token' => $reservation->token,
                        'run_id' => $runId, 'engine_key' => $run->engine_key, 'kind' => 'engine_call',
                        'occurred_at' => $reservation->occurredAt, 'calls' => 1,
                        'input_tokens' => 0, 'output_tokens' => 0, 'search_calls' => 0,
                        'cost_usd' => $reservation->price->price_per_call_usd,
                        'billed_to' => $reservation->billingAccount->value,
                    ]);
                    $run->forceFill([
                        'status' => 'submitted', 'provider_task_id' => $providerId,
                        'provider_api_key' => $reservation->billingAccount === BillingAccount::Byok ? $reservation->request->apiKey : null,
                        'provider_billed_to' => $reservation->billingAccount->value,
                        'provider_submitted_at' => $reservation->occurredAt, 'provider_price_id' => $reservation->price->id,
                        'provider_submissions' => $run->provider_submissions + 1,
                        'enqueued_at' => null, 'next_poll_at' => now()->addSeconds(config('collection.poll_seconds')),
                    ]);
                    $this->reservations->release($run);
                });
            } catch (Throwable $failure) {
                $this->rls->run([$tenantId], fn () => $this->reservations->releaseIfOwned($runId, $reservation->token));
                throw $failure;
            }
        } finally {
            if ($session !== null) {
                try {
                    $this->reservations->unlock($runId, $session);
                } catch (Throwable) {
                    DB::disconnect();
                }
            }
        }
    }

    private function wait(int $runId): void
    {
        $this->rls->run([current_tenant_id()], fn () => Run::query()->whereKey($runId)->update([
            'enqueued_at' => null, 'next_poll_at' => now()->addSeconds(config('collection.poll_seconds')),
        ]));
    }
}
