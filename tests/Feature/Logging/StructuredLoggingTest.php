<?php

use App\Logging\AddLogContext;
use App\Logging\LogContext;
use App\Models\User;
use App\Tenancy\TenantContext;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\GoogleCloudLoggingFormatter;
use Monolog\Handler\TestHandler;
use Symfony\Component\Mailer\Exception\TransportException;

// T39-F7: structured JSON for Cloud Logging, tenant_id/user_id/run_id on every record, and no data from
// exception messages (docs/spec.md 16).

/** A channel that keeps its records, with the same context tap as the real channels. */
function captureChannel(): TestHandler
{
    config([
        'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'tap' => [AddLogContext::class]],
        'logging.default' => 'capture',
    ]);
    Log::forgetChannel('capture');

    return Log::channel('capture')->getLogger()->getHandlers()[0];
}

test('production logs to stderr as Cloud Logging JSON at info and up', function () {
    $previous = Env::get('LOG_LEVEL');
    putenv('LOG_LEVEL');
    unset($_ENV['LOG_LEVEL'], $_SERVER['LOG_LEVEL']);
    try {
        $stderr = (require config_path('logging.php'))['channels']['stderr'];
    } finally {
        if ($previous !== null) {
            putenv("LOG_LEVEL={$previous}");
            $_ENV['LOG_LEVEL'] = $_SERVER['LOG_LEVEL'] = $previous;
        }
    }

    expect($stderr['formatter'])->toBe(GoogleCloudLoggingFormatter::class)
        ->and($stderr['level'])->toBe('info')
        ->and($stderr['tap'])->toBe([AddLogContext::class]);
});

test('a record is one JSON line with severity and the context fields', function () {
    $stream = fopen('php://memory', 'r+');
    config(['logging.channels.json' => [...config('logging.channels.stderr'), 'handler_with' => ['stream' => $stream], 'level' => 'debug']]);
    app(TenantContext::class)->set(41);
    app(LogContext::class)->setRun(7);

    Log::channel('json')->warning('run.slow', ['seconds' => 3]);

    rewind($stream);
    $line = json_decode((string) stream_get_contents($stream), true, flags: JSON_THROW_ON_ERROR);
    expect($line['severity'])->toBe('WARNING')
        ->and($line['message'])->toBe('run.slow')
        ->and($line['context'])->toBe(['seconds' => 3])
        ->and($line['extra'])->toBe(['tenant_id' => 41, 'run_id' => 7]);
});

test('every record gets tenant_id, user_id and run_id, and nothing carries over to the next job', function () {
    $handler = captureChannel();
    $user = User::factory()->create();
    $this->actingAs($user);
    app(TenantContext::class)->set(12);
    app(LogContext::class)->setRun(99);

    Log::info('first');
    app()->forgetScopedInstances();
    auth()->forgetGuards();
    Log::info('second');

    [$first, $second] = $handler->getRecords();
    expect($first->extra)->toBe(['tenant_id' => 12, 'user_id' => $user->id, 'run_id' => 99])
        ->and($second->extra)->toBe([]);
});

test('an exception with data in its message is reported without the message', function (Throwable $exception, string $event) {
    $handler = captureChannel();

    report($exception);

    $records = $handler->getRecords();
    expect($records)->toHaveCount(1)
        ->and($records[0]->message)->toBe($event)
        ->and(json_encode(array_map(fn ($record) => $record->toArray(), $records)))->not->toContain('secret');
})->with([
    'database' => fn () => [new QueryException('pgsql', 'insert into users (email) values (?)', ['secret@example.test'],
        new PDOException('SQLSTATE[23505]: Unique violation: DETAIL: Key (email)=(secret@example.test) already exists.')), 'database.query_failed'],
    'mail' => fn () => [new TransportException('550 5.1.1 <secret@example.test>: Recipient address rejected'), 'mail.transport_failed'],
    'http response' => fn () => [new RequestException(new Response(new Psr7Response(500, [], 'secret answer text from the engine'))), 'http.request_failed'],
    'http connection' => fn () => [new ConnectionException('cURL error 28 for https://api.example.test/v1?key=secret'), 'http.connection_failed'],
]);

test('a real database error keeps the SQLSTATE and the SQL with placeholders, not the values', function () {
    User::factory()->create(['email' => 'leaked-address@example.test']);
    $handler = captureChannel();

    try {
        // A savepoint, so the test's own transaction survives the failed statement.
        DB::transaction(fn () => User::factory()->create(['email' => 'leaked-address@example.test']));
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('leaked-address@example.test');
        report($e);
    }

    $record = $handler->getRecords()[0];
    expect($record->context)->toMatchArray(['exception' => UniqueConstraintViolationException::class, 'sqlstate' => '23505', 'connection' => 'pgsql'])
        ->and($record->context['sql'])->toStartWith('insert into "users"')->toContain('?')
        ->and(json_encode($record->toArray()))->not->toContain('leaked-address');
});

test('other exceptions are still reported as before', function () {
    $handler = captureChannel();

    report(new RuntimeException('engine_collection_failed'));

    expect($handler->getRecords()[0]->message)->toBe('engine_collection_failed');
});
