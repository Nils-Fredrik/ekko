<?php

namespace App\Work;

/**
 * Processes one kind of work (ADR 0032). Runs in the tenant's context and in one row-level security transaction
 * together with the acknowledgement, so its database writes and the acknowledgement commit or roll back together.
 *
 * It must read the current state of its scope rather than rely on what was signalled, and it must be idempotent:
 * a signal can be processed again after a crash or a lost lease. It must finish within the lease
 * (ekko.work.lease_seconds); a handler that calls a provider must make that call safe to repeat.
 */
interface WorkHandler
{
    public function handle(WorkClaim $claim): WorkOutcome;
}
