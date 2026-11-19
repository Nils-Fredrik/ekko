<?php

namespace App\Work;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Durable registration of event-driven processing work (docs/spec.md 7.1, ADR 0032).
 *
 * signal() raises the version of the tenant's row for a kind and scope in the caller's transaction, so the work
 * exists exactly when the data change commits. The wake-up is published only after that commit. A processor
 * leases the current version (claim), and acknowledges only that version in the same transaction as its own
 * writes; anything signalled while it ran stays pending. A lease expires, so a crashed processor's work is
 * taken over, and the stale token then acknowledges nothing.
 *
 * All statements run in the caller's transaction and under its row-level security; the tenant comes from the
 * context and is also in every WHERE clause (both layers of docs/spec.md 5.3). Times come from the application
 * clock, so tests can travel.
 */
final class WorkSignals
{
    /** @var array<string, true> Wake-up keys this instance has published; a scoped instance, so per job or request. */
    private array $published = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly WorkPublisher $publisher,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Registers work for the tenant in the context. Must run in the transaction of the data change it stands for.
     * Repeated signals for the same kind and scope coalesce into the same row.
     *
     * @param  CarbonImmutable|null  $notBefore  Earliest processing time; the earliest of pending signals wins.
     */
    public function signal(WorkKind $kind, string $scope, ?CarbonImmutable $notBefore = null): void
    {
        $tenantId = $this->context->require();
        if (! $kind->accepts($scope)) {
            throw new InvalidArgumentException('invalid_work_scope');
        }

        $db = $this->connection();
        if ($db->transactionLevel() === 0) {
            // Outside a transaction the signal could commit without its data change, or the other way round.
            throw new LogicException('work_signal_needs_transaction');
        }

        $now = $this->now();
        $available = $notBefore !== null && $notBefore->greaterThan($now) ? $notBefore->utc() : $now;

        // An idle row starts a new pending epoch at the requested time. A pending row keeps the earliest time,
        // except while it waits out a retry backoff. A leased row only gets a higher version.
        $row = $db->selectOne(<<<'SQL'
            INSERT INTO work_signals AS w
                (tenant_id, kind, scope, version, processed_version, available_at, pending_since, last_signaled_at, attempts, created_at, updated_at)
            VALUES (?, ?, ?, 1, 0, ?, ?, ?, 0, ?, ?)
            ON CONFLICT (tenant_id, kind, scope) DO UPDATE SET
                version = w.version + 1,
                last_signaled_at = EXCLUDED.last_signaled_at,
                updated_at = EXCLUDED.updated_at,
                pending_since = CASE WHEN w.version = w.processed_version THEN EXCLUDED.pending_since ELSE w.pending_since END,
                available_at = CASE
                    WHEN w.version = w.processed_version THEN EXCLUDED.available_at
                    WHEN w.lease_token IS NULL AND w.attempts > 0 THEN w.available_at
                    ELSE LEAST(w.available_at, EXCLUDED.available_at)
                END
            RETURNING id, processed_version, available_at, lease_token, attempts
            SQL, [$tenantId, $kind->value, $scope, $this->ts($available), $this->ts($now), $this->ts($now), $this->ts($now), $this->ts($now)]);

        // A leased row is woken by its acknowledgement, a row in backoff by its failure; both already published.
        if ($row->lease_token === null && (int) $row->attempts === 0) {
            $wakeUp = new WorkWakeUp($tenantId, (int) $row->id, (int) $row->processed_version, CarbonImmutable::parse($row->available_at)->utc());
            $db->afterCommit(fn () => $this->publish($wakeUp));
        }
    }

    /**
     * Leases the signal's current version, if it is pending, due and not leased by a live processor. Commit the
     * claim before processing, so the lease is visible to other processors while this one works.
     */
    public function claim(int $signalId): ?WorkClaim
    {
        $tenantId = $this->context->require();
        $now = $this->now();
        $expires = $now->addSeconds($this->leaseSeconds());

        // The subquery locks the row first, so the takeover check sees the token that is actually being replaced.
        $row = $this->connection()->selectOne(<<<'SQL'
            UPDATE work_signals AS w SET
                lease_token = ?, lease_version = w.version, leased_at = ?, lease_expires_at = ?,
                attempts = w.attempts + 1, updated_at = ?
            FROM (SELECT id, lease_token AS previous FROM work_signals WHERE id = ? AND tenant_id = ? FOR UPDATE) AS old
            WHERE w.id = old.id
                AND w.version > w.processed_version
                AND w.available_at <= ?
                AND (w.lease_token IS NULL OR w.lease_expires_at <= ?)
            RETURNING w.id, w.kind, w.scope, w.version, w.attempts, old.previous IS NOT NULL AS taken_over
            SQL, [$token = (string) Str::uuid(), $this->ts($now), $this->ts($expires), $this->ts($now), $signalId, $tenantId, $this->ts($now), $this->ts($now)]);

        if ($row === null) {
            return null;
        }

        if ($row->taken_over) {
            Log::warning('Work signal lease expired and was taken over.', ['tenant_id' => $tenantId, 'work_signal_id' => $signalId]);
        }

        return new WorkClaim($tenantId, (int) $row->id, WorkKind::from($row->kind), $row->scope, (int) $row->version, $token, (int) $row->attempts, $expires);
    }

    /**
     * When a pending signal that claim() refused can be tried again: the end of a live lease or a backoff.
     * Null when there is nothing to do.
     */
    public function busyUntil(int $signalId): ?CarbonImmutable
    {
        $row = $this->connection()->selectOne(
            'SELECT version, processed_version, available_at, lease_token, lease_expires_at FROM work_signals WHERE id = ? AND tenant_id = ?',
            [$signalId, $this->context->require()],
        );
        if ($row === null || (int) $row->version <= (int) $row->processed_version) {
            return null;
        }

        $until = CarbonImmutable::parse($row->available_at)->utc();
        if ($row->lease_token !== null) {
            $until = $until->max(CarbonImmutable::parse($row->lease_expires_at)->utc());
        }

        return $until->isAfter($this->now()) ? $until : null;
    }

    /**
     * Marks the leased version as processed, in the handler's transaction. Versions signalled after the claim stay
     * pending and are woken after commit. Throws {@see LeaseLost} when another processor has taken over.
     */
    public function acknowledge(WorkClaim $claim): void
    {
        $now = $this->now();
        $row = $this->connection()->selectOne(<<<'SQL'
            UPDATE work_signals SET
                processed_version = GREATEST(processed_version, lease_version),
                pending_since = CASE WHEN version > lease_version THEN leased_at END,
                lease_token = NULL, lease_version = NULL, leased_at = NULL, lease_expires_at = NULL,
                attempts = 0, last_error = NULL, processed_at = ?, updated_at = ?
            WHERE id = ? AND tenant_id = ? AND lease_token = ?
            RETURNING version, processed_version, available_at
            SQL, [$this->ts($now), $this->ts($now), $claim->signalId, $claim->tenantId, $claim->token]);

        if ($row === null) {
            throw new LeaseLost;
        }

        if ((int) $row->version > (int) $row->processed_version) {
            $this->wakeAfterCommit($claim, (int) $row->processed_version, $row->available_at, WorkWakeUp::REASON_REMAINING);
        }
    }

    /**
     * Gives the lease back without acknowledging or counting a failure, and wakes the signal at $at (an external
     * batch that is still running). Throws {@see LeaseLost} when another processor has taken over.
     */
    public function defer(WorkClaim $claim, CarbonImmutable $at): void
    {
        $now = $this->now();
        $row = $this->connection()->selectOne(<<<'SQL'
            UPDATE work_signals SET
                lease_token = NULL, lease_version = NULL, leased_at = NULL, lease_expires_at = NULL,
                attempts = GREATEST(attempts - 1, 0), available_at = ?, updated_at = ?
            WHERE id = ? AND tenant_id = ? AND lease_token = ?
            RETURNING processed_version, available_at
            SQL, [$this->ts($at->greaterThan($now) ? $at->utc() : $now), $this->ts($now), $claim->signalId, $claim->tenantId, $claim->token]);

        if ($row === null) {
            throw new LeaseLost;
        }

        $this->wakeAfterCommit($claim, (int) $row->processed_version, $row->available_at, WorkWakeUp::REASON_DEFER);
    }

    /**
     * Records a failed attempt in its own transaction, after the handler's transaction rolled back: the lease is
     * given back and the signal waits with exponential backoff. Only the exception class is stored and logged.
     * Returns false when the lease was already taken over.
     */
    public function fail(WorkClaim $claim, Throwable $failure): bool
    {
        $now = $this->now();
        $retryAt = $now->addSeconds($this->backoffSeconds($claim->attempt));
        $row = $this->connection()->selectOne(<<<'SQL'
            UPDATE work_signals SET
                lease_token = NULL, lease_version = NULL, leased_at = NULL, lease_expires_at = NULL,
                last_error = ?, available_at = ?, updated_at = ?
            WHERE id = ? AND tenant_id = ? AND lease_token = ?
            RETURNING processed_version, available_at, attempts
            SQL, [Str::limit(class_basename($failure), 120, ''), $this->ts($retryAt), $this->ts($now), $claim->signalId, $claim->tenantId, $claim->token]);

        if ($row === null) {
            return false;
        }

        $context = ['tenant_id' => $claim->tenantId, 'work_signal_id' => $claim->signalId, 'work_kind' => $claim->kind->value, 'attempts' => (int) $row->attempts, 'error_type' => $failure::class];
        if ((int) $row->attempts >= (int) config('ekko.work.alert_after_attempts')) {
            Log::error('Work signal keeps failing.', $context);
        } else {
            Log::warning('Work signal failed; it is retried after a backoff.', $context);
        }

        $this->wakeAfterCommit($claim, (int) $row->processed_version, $row->available_at, WorkWakeUp::REASON_RETRY);

        return true;
    }

    /**
     * The wake-ups the recovery sweep must publish again for the tenant in the context: pending signals that are
     * due and have waited past the grace period without a live lease. Covers a crash between commit and publish,
     * a failed publish and an expired lease.
     *
     * @return list<WorkWakeUp>
     */
    public function stranded(CarbonImmutable $now): array
    {
        $tenantId = $this->context->require();
        $cutoff = $now->subSeconds(max(0, (int) config('ekko.work.recover_after_seconds')));

        $rows = $this->connection()->select(<<<'SQL'
            SELECT id, processed_version FROM work_signals
            WHERE tenant_id = ? AND version > processed_version
                AND ((lease_token IS NULL AND available_at <= ?) OR (lease_token IS NOT NULL AND lease_expires_at <= ?))
            ORDER BY available_at, id
            SQL, [$tenantId, $this->ts($cutoff), $this->ts($now)]);

        return array_map(fn (object $row): WorkWakeUp => new WorkWakeUp(
            $tenantId, (int) $row->id, (int) $row->processed_version, $now->utc(), WorkWakeUp::REASON_RECOVERY, $now->utc(),
        ), $rows);
    }

    /**
     * Publishes a wake-up. Never throws: the signal is durable, and a wake-up that does not go out is published
     * again by the recovery sweep.
     */
    public function publish(WorkWakeUp $wakeUp): bool
    {
        $key = $wakeUp->key();
        if (isset($this->published[$key])) {
            return true;
        }

        try {
            $this->publisher->publish($wakeUp);
        } catch (Throwable $failure) {
            Log::warning('Work wake-up was not published; the recovery sweep publishes it again.', [
                'tenant_id' => $wakeUp->tenantId,
                'work_signal_id' => $wakeUp->signalId,
                'error_type' => $failure::class,
            ]);

            return false;
        }

        $this->published[$key] = true;

        return true;
    }

    private function wakeAfterCommit(WorkClaim $claim, int $epoch, string $availableAt, string $reason): void
    {
        $wakeUp = new WorkWakeUp($claim->tenantId, $claim->signalId, $epoch, CarbonImmutable::parse($availableAt)->utc(), $reason);
        $this->connection()->afterCommit(fn () => $this->publish($wakeUp));
    }

    private function backoffSeconds(int $attempt): int
    {
        $base = max(1, (int) config('ekko.work.retry_base_seconds'));
        $cap = max($base, (int) config('ekko.work.retry_max_seconds'));
        $jitter = max(0, (int) config('ekko.work.retry_jitter_seconds'));
        $delay = min($cap, $base * 2 ** min(20, max(0, $attempt - 1)));

        return $delay + ($jitter > 0 ? random_int(0, $jitter) : 0);
    }

    private function leaseSeconds(): int
    {
        return max(1, (int) config('ekko.work.lease_seconds'));
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private function ts(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d H:i:s.uP');
    }
}
