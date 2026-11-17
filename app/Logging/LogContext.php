<?php

namespace App\Logging;

/**
 * The run the current job works on, for the run_id field in the log (docs/spec.md 16, T39-F7).
 * Registered as a scoped instance, so it is reset between requests and between queued jobs.
 */
final class LogContext
{
    private ?int $runId = null;

    public function setRun(int $runId): void
    {
        $this->runId = $runId;
    }

    public function runId(): ?int
    {
        return $this->runId;
    }
}
