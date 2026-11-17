<?php

namespace App\Logging;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Monolog\LogRecord;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Removes the message of exceptions that can carry data from the log record (docs/spec.md 16, T39-F7):
 * - a database error has the SQL with its bindings and PostgreSQL's DETAIL line, such as answer text or an
 *   e-mail address; only the SQLSTATE, the connection and the SQL with placeholders are kept,
 * - a mail error has the server's reply, often with the recipient's address,
 * - an HTTP client error has the start of the response body, which for an engine is answer text, and the URL.
 * It works on the record, not in the exception handler, so report() listeners still get the exception, and an
 * exception passed to Log::error() directly is scrubbed too.
 */
final class ScrubExceptions
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $exception = $record->context['exception'] ?? null;
        if (! $exception instanceof Throwable) {
            return $record;
        }

        $fields = match (true) {
            $exception instanceof QueryException => ['database.query_failed', [
                'sqlstate' => (string) $exception->getCode(),
                'connection' => $exception->getConnectionName(),
                'sql' => $exception->getSql(),
            ]],
            $exception instanceof TransportExceptionInterface => ['mail.transport_failed', ['code' => $exception->getCode()]],
            $exception instanceof RequestException => ['http.request_failed', [
                'status' => $exception->response->status(),
                'host' => $exception->response->effectiveUri()?->getHost(),
            ]],
            $exception instanceof ConnectionException => ['http.connection_failed', []],
            default => null,
        };
        if ($fields === null) {
            return $record;
        }

        [$message, $details] = $fields;
        $context = $record->context;
        unset($context['exception']);

        return $record->with(message: $message, context: [
            ...$context,
            'exception' => $exception::class,
            ...$details,
            'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $exception->getFile()),
            'line' => $exception->getLine(),
        ]);
    }
}
