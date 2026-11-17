<?php

namespace App\Onboarding;

use App\Scheduling\CollectionLocks;
use Closure;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps analyses in the cache, one key per tenant and analysis (ADR 0018). The tenant is part of the key,
 * so an id from another tenant finds nothing, and find() checks it once more on the stored value.
 *
 * Analyses that are queued or running are also kept in a per-tenant list of pending analyses, so the quota check
 * can count what has been started but not billed yet. The list is changed only under the tenant's lock
 * (reserving()). The lock is a row in cache_locks taken with INSERT ... ON CONFLICT DO NOTHING
 * (CollectionLocks, ADR 0020): the request runs in a transaction (row-level security), and Laravel's database
 * lock would abort that transaction on a key conflict in PostgreSQL. An entry that is never finished (a worker
 * that died) runs out after ekko.wizard.pending_reservation_seconds.
 */
final class AnalysisStore
{
    /**
     * Runs $start under the tenant's lock with the number of pending analyses. Use it to check the quota and create
     * the analysis as one step, so two requests cannot both pass the check on the same remaining quota.
     *
     * @template T
     *
     * @param  Closure(int): T  $start
     * @return T
     */
    public function reserving(int $tenantId, Closure $start): mixed
    {
        return CollectionLocks::make($this->lockKey($tenantId), 10)->block(5, fn () => $start($this->pending($tenantId)));
    }

    /**
     * Creates an analysis and counts it as pending. Call inside reserving().
     *
     * @param  array<string, mixed>  $input
     */
    public function create(int $tenantId, int $userId, string $kind, array $input): Analysis
    {
        $analysis = new Analysis((string) Str::uuid(), $tenantId, $userId, $kind, $input);
        $this->save($analysis);

        $pending = $this->pendingList($tenantId);
        $pending[$analysis->id] = now()->addSeconds((int) config('ekko.wizard.pending_reservation_seconds'))->getTimestamp();
        Cache::put($this->pendingKey($tenantId), $pending, now()->addDay());

        return $analysis;
    }

    /** Analyses of the tenant that are queued or running, and not billed yet. */
    public function pending(int $tenantId): int
    {
        return count($this->pendingList($tenantId));
    }

    public function find(int $tenantId, string $id): ?Analysis
    {
        $data = Cache::get($this->key($tenantId, $id));
        if (! is_array($data) || ($data['tenant_id'] ?? null) !== $tenantId) {
            return null;
        }

        $analysis = Analysis::fromArray($data);
        if ($this->confirmations()->has($this->key($tenantId, $id))) {
            $analysis->status = Analysis::CONFIRMED;
        }

        return $analysis;
    }

    /** The marker commits with the configuration and queue, even when proposals use Redis/array cache. */
    public function markConfirmed(Analysis $analysis): void
    {
        $this->confirmations()->put($this->key($analysis->tenantId, $analysis->id), true,
            now()->addHours((int) config('ekko.wizard.proposal_ttl_hours')));
    }

    private function confirmations(): Repository
    {
        return new Repository(new DatabaseStore(DB::connection(), 'cache', 'onboarding-confirmed:'));
    }

    public function save(Analysis $analysis): void
    {
        $this->write($analysis);

        if ($analysis->isFinished()) {
            CollectionLocks::make($this->lockKey($analysis->tenantId), 10)->block(5, function () use ($analysis): void {
                $this->forgetPending($analysis);
            });
        }
    }

    private function write(Analysis $analysis): void
    {
        Cache::put(
            $this->key($analysis->tenantId, $analysis->id),
            $analysis->toArray(),
            now()->addHours((int) config('ekko.wizard.proposal_ttl_hours')),
        );
    }

    private function forgetPending(Analysis $analysis): void
    {
        $pending = $this->pendingList($analysis->tenantId);
        unset($pending[$analysis->id]);
        Cache::put($this->pendingKey($analysis->tenantId), $pending, now()->addDay());
    }

    /** @return array<string, int> Analysis id => when the reservation runs out (Unix time). */
    private function pendingList(int $tenantId): array
    {
        $now = now()->getTimestamp();
        $pending = Cache::get($this->pendingKey($tenantId));

        return is_array($pending) ? array_filter($pending, fn (mixed $until): bool => is_int($until) && $until > $now) : [];
    }

    private function pendingKey(int $tenantId): string
    {
        return "onboarding:pending:{$tenantId}";
    }

    private function lockKey(int $tenantId): string
    {
        return "onboarding:lock:{$tenantId}";
    }

    private function key(int $tenantId, string $id): string
    {
        return "onboarding:analysis:{$tenantId}:{$id}";
    }
}
