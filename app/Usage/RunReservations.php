<?php

namespace App\Usage;

use App\Engines\EngineCallFailed;
use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RunReservations
{
    // Longer than the 420-second worker timeout. Retain the reservation through a lost
    // database session while that worker could still be waiting for an external response.
    private const MINIMUM_SECONDS = 450;

    // PostgreSQL splits bigint advisory keys into two 32-bit fields in pg_locks.
    private const LOCKS = <<<'SQL'
        SELECT 1 FROM pg_locks
        WHERE locktype = 'advisory' AND granted AND objsubid = 1
          AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
        SQL;

    /** @return Builder<Run> */
    public static function active(CarbonImmutable $now): Builder
    {
        return Run::query()->whereNotNull('quota_reserved_at')
            ->where(function (Builder $query) use ($now): void {
                $query->where('quota_reserved_at', '>', self::expiresBefore($now))
                    ->orWhereRaw('EXISTS ('.self::LOCKS.' AND ((classid::bigint << 32) | objid::bigint) = -runs.id)');
            });
    }

    public function lock(Run $run, CarbonImmutable $now): int
    {
        $recent = $run->quota_reserved_at?->isAfter(self::expiresBefore($now)) ?? false;
        $locked = DB::scalar('SELECT EXISTS ('.self::LOCKS.' AND ((classid::bigint << 32) | objid::bigint) = CAST(? AS bigint))', [-$run->id], false);

        $sessionId = $recent || $locked
            ? null
            : DB::scalar('SELECT pg_backend_pid() WHERE pg_try_advisory_lock(CAST(? AS bigint))', [-$run->id], false);

        if ($sessionId === null) {
            throw new EngineCallFailed('run_already_reserved');
        }

        return (int) $sessionId;
    }

    public function unlock(int $runId, int $sessionId): void
    {
        DB::scalar('SELECT pg_advisory_unlock(CAST(? AS bigint)) WHERE pg_backend_pid() = ?', [-$runId, $sessionId], false);
    }

    public function reserve(Run $run, PlannedRun $planned, CarbonImmutable $at): string
    {
        $token = (string) Str::uuid();
        $run->forceFill([
            'quota_reservation_token' => $token,
            'quota_reserved_at' => $at,
            'quota_reserved_cost_usd' => (string) $planned->estimatedCostUsd,
            'quota_reserved_billed_to' => $planned->billingAccount->value,
        ])->save();

        return $token;
    }

    public function release(Run $run): void
    {
        $run->forceFill([
            'quota_reservation_token' => null,
            'quota_reserved_at' => null,
            'quota_reserved_cost_usd' => null,
            'quota_reserved_billed_to' => null,
        ])->save();
    }

    public function releaseIfOwned(int $runId, string $token): void
    {
        $run = Run::query()->lockForUpdate()->find($runId);

        if ($run !== null && $run->quota_reservation_token === $token) {
            $this->release($run);
        }
    }

    private static function expiresBefore(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subSeconds(max(self::MINIMUM_SECONDS, config()->integer('queue.connections.database.retry_after')));
    }
}
