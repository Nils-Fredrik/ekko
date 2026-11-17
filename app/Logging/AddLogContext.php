<?php

namespace App\Logging;

use App\Tenancy\TenantContext;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Auth;
use Monolog\LogRecord;

/**
 * Adds tenant_id, user_id and run_id to every log record as fields under `extra` (docs/spec.md 16, T39-F7).
 * The values are read when the record is written, from the scoped TenantContext and LogContext and the
 * signed-in user, so nothing carries over from one request or queued job to the next.
 */
final class AddLogContext
{
    /** Tap for a log channel (config/logging.php): the context fields, and exception messages scrubbed. */
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new ScrubExceptions);
        $logger->pushProcessor(fn (LogRecord $record): LogRecord => $this->process($record));
    }

    public function process(LogRecord $record): LogRecord
    {
        $fields = array_filter([
            'tenant_id' => app(TenantContext::class)->id(),
            // hasUser() only reads a user that is already resolved: logging never triggers a session or database lookup.
            'user_id' => Auth::hasUser() ? Auth::id() : null,
            'run_id' => app(LogContext::class)->runId(),
        ], fn (mixed $value): bool => $value !== null);

        return $fields === [] ? $record : $record->with(extra: [...$record->extra, ...$fields]);
    }
}
