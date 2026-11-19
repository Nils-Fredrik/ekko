<?php

namespace App\Work;

use App\Enums\TenantStatus;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Processes one work signal of the tenant in the context, in three short row-level security transactions
 * (ADR 0032):
 *
 * 1. claim: lease the current version and commit, so the lease is visible to other processors.
 * 2. handle: the handler's writes and the acknowledgement (or deferral) commit together. A crash or an
 *    exception rolls both back; a lost lease rolls back the handler's writes.
 * 3. on failure: give the lease back with backoff in a transaction of its own.
 *
 * Must not run inside another transaction: phase 1 would then not be visible to anyone else until the end.
 */
final class WorkProcessor
{
    public function __construct(
        private readonly WorkSignals $signals,
        private readonly TenantRls $rls,
        private readonly TenantContext $context,
        private readonly Container $container,
    ) {}

    public function process(int $signalId): WorkResult
    {
        $tenantId = $this->context->require();

        // Inside another transaction the lease would stay invisible until that one ends. Under the test runner one
        // level is the transactional test case itself (RefreshDatabase); a Settlement test checks the production limit
        // of 0 by running outside the testing environment.
        if (DB::transactionLevel() > (app()->runningUnitTests() ? 1 : 0)) {
            throw new LogicException('work_processor_must_not_run_in_a_transaction');
        }

        // A closed tenant waits to be deleted (T39-F6): no more processing, and nothing is acknowledged.
        if (Team::query()->whereKey($tenantId)->value('status') === TenantStatus::Closed) {
            return new WorkResult(WorkResult::IDLE);
        }

        $claim = $this->rls->run([$tenantId], fn () => $this->signals->claim($signalId));
        if ($claim === null) {
            $until = $this->rls->run([$tenantId], fn () => $this->signals->busyUntil($signalId));

            return new WorkResult($until === null ? WorkResult::IDLE : WorkResult::BUSY, $until);
        }

        try {
            $outcome = $this->rls->run([$tenantId], function () use ($claim): WorkOutcome {
                $outcome = $this->handler($claim->kind)->handle($claim);
                if ($outcome->retryAt === null) {
                    $this->signals->acknowledge($claim);
                } else {
                    $this->signals->defer($claim, $outcome->retryAt);
                }

                return $outcome;
            });
        } catch (LeaseLost) {
            Log::warning('Work signal lease was lost; the new owner processes it.', ['tenant_id' => $tenantId, 'work_signal_id' => $signalId]);

            return new WorkResult(WorkResult::LEASE_LOST);
        } catch (Throwable $failure) {
            return new WorkResult($this->rls->run([$tenantId], fn () => $this->signals->fail($claim, $failure)));
        }

        return new WorkResult($outcome->isDone() ? WorkResult::PROCESSED : WorkResult::DEFERRED);
    }

    private function handler(WorkKind $kind): WorkHandler
    {
        $class = config('ekko.work.handlers.'.$kind->value);
        $handler = is_string($class) && $class !== '' ? $this->container->make($class) : null;

        return $handler instanceof WorkHandler ? $handler : throw new MissingWorkHandler($kind);
    }
}
