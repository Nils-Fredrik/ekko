<?php

use App\Exceptions\Handler;
use App\Jobs\CollectEngineRun;
use App\Jobs\Middleware\SetTenantContext;
use App\Jobs\PollExtractionBatch;
use App\Logging\LogContext;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\GoogleCloudLoggingFormatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Fixtures\LoggingTenantJob;
use Tests\Support\Mcp\McpTestClient;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->logFile = tempnam(sys_get_temp_dir(), 'ekko-log-test-');
    config()->set('logging.default', 'stderr');
    config()->set('logging.channels.stderr.handler_with.stream', $this->logFile);
    config()->set('logging.channels.stderr.level', 'info');
    Log::forgetChannel('stderr');
    app(LogContext::class)->clear();
});

afterEach(function () {
    Log::forgetChannel('stderr');
    unlink($this->logFile);
});

function structuredLogRecords(string $path): array
{
    return array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

test('stderr writes JSON with Cloud Logging severity and suppresses debug records', function () {
    Log::debug('not-for-production');
    Log::warning('collection.failed', ['run_id' => 37]);
    $records = structuredLogRecords($this->logFile);

    expect(config('logging.channels.stderr.formatter'))->toBe(GoogleCloudLoggingFormatter::class)
        ->and($records)->toHaveCount(1)
        ->and($records[0]['severity'])->toBe('WARNING')
        ->and($records[0]['time'])->toBeString()
        ->and($records[0]['message'])->toBe('collection.failed')
        ->and($records[0]['context'])->toMatchArray(['tenant_id' => null, 'user_id' => null, 'run_id' => 37]);
});

test('report removes SQL bindings, mail debug, previous messages, custom context and trace arguments', function () {
    $query = new QueryException('pgsql', 'insert into answers values (?)', ['synthetic-answer@example.test'], new PDOException('synthetic-db-password'));
    $mail = new TransportException('synthetic-mail-body');
    $mail->appendDebug('synthetic-smtp-secret');
    $wrapped = new RuntimeException('synthetic-wrapper', previous: $query);
    $custom = new class('synthetic-custom-message') extends RuntimeException
    {
        public function context(): array
        {
            return ['secret' => 'synthetic-custom-context'];
        }
    };

    $original = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    try {
        $withArgument = (fn (string $secret) => new RuntimeException('synthetic-argument-message'))('synthetic-trace-secret');
    } finally {
        ini_set('zend.exception_ignore_args', $original);
    }

    foreach ([$query, $mail, $wrapped, $custom, $withArgument] as $failure) {
        report($failure);
    }
    $raw = file_get_contents($this->logFile);
    $records = structuredLogRecords($this->logFile);
    expect($records)->toHaveCount(5)
        ->and($raw)->not->toContain('synthetic-', 'insert into answers', 'getBindings', '"args"')
        ->and($records[0]['context']['exception']['type'])->toBe(QueryException::class)
        ->and($records[1]['context']['exception']['type'])->toBe(TransportException::class)
        ->and($records[2]['context']['exception']['causes'])->toBe([QueryException::class, PDOException::class]);
    foreach ($records as $record) {
        expect($record['severity'])->toBe('ERROR')
            ->and($record['message'])->toBe('Application exception.')
            ->and($record['context']['exception']['file'])->toBe(__FILE__)
            ->and($record['context']['exception']['line'])->toBeInt();
    }
});

test('direct PSR exception logging is also sanitized before interpolation and JSON formatting', function () {
    $failure = new RuntimeException('synthetic-secret', previous: new RuntimeException('synthetic-previous'));
    Log::error($failure->getMessage(), ['nested' => ['exception' => $failure]]);

    expect(file_get_contents($this->logFile))->not->toContain('synthetic-')
        ->and(structuredLogRecords($this->logFile)[0]['context']['nested']['exception']['type'])->toBe(RuntimeException::class);
});

test('production console rendering cannot print the original exception after safe reporting', function () {
    $this->app->instance('env', 'production');
    $output = new BufferedOutput;
    $failure = new QueryException('pgsql', 'select synthetic-secret', [], new PDOException('synthetic-password'));
    $handler = app(ExceptionHandler::class);
    expect($handler)->toBeInstanceOf(Handler::class);
    $handler->report($failure);
    $handler->renderForConsole($output, $failure);

    expect($output->fetch())->toContain('Command failed.')->not->toContain('synthetic-')
        ->and(file_get_contents($this->logFile))->not->toContain('synthetic-')
        ->and(structuredLogRecords($this->logFile))->toHaveCount(1);
});

test('HTTP logs follow the authenticated tenant and clear before a guest or global route', function () {
    Route::middleware('web')->get('/_test/logging', function () {
        Log::info('http.test');

        return response('ok');
    });
    Route::get('/_test/global-logging', function () {
        Log::info('global.test');

        return response('ok');
    });
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user)->get('/_test/logging')->assertOk();
    expect(Log::sharedContext())->toMatchArray(['tenant_id' => null, 'user_id' => null, 'run_id' => null]);
    auth()->logout();
    $this->get('/_test/global-logging')->assertOk();
    $this->get('/_test/logging')->assertOk();

    $records = structuredLogRecords($this->logFile);
    expect($records[0]['context'])->toMatchArray(['tenant_id' => $user->current_team_id, 'user_id' => $user->id, 'run_id' => null]);
    foreach (array_slice($records, 1) as $record) {
        expect($record['context'])->toMatchArray(['tenant_id' => null, 'user_id' => null, 'run_id' => null]);
    }
});

test('HTTP exception reporting has IDs and leaves no shared context after the response', function () {
    Route::middleware('web')->get('/_test/logging-failure', fn () => throw new RuntimeException('synthetic-http-secret'));
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user)->get('/_test/logging-failure')->assertStatus(500);

    $record = structuredLogRecords($this->logFile)[0];
    expect($record['context'])->toMatchArray(['tenant_id' => $user->current_team_id, 'user_id' => $user->id])
        ->and(file_get_contents($this->logFile))->not->toContain('synthetic-http-secret')
        ->and(Log::sharedContext())->toMatchArray(['tenant_id' => null, 'user_id' => null, 'run_id' => null]);
});

test('tenant jobs clear actor and run IDs between jobs and retain failed job context after unwinding', function () {
    app(TenantContext::class)->set(90);
    app(LogContext::class)->share(['user_id' => 80, 'run_id' => 70]);

    try {
        dispatch_sync(new LoggingTenantJob(12, true));
    } catch (RuntimeException $failure) {
        report($failure);
    }
    dispatch_sync(new LoggingTenantJob(13));
    Log::info('caller.restored');

    $records = structuredLogRecords($this->logFile);
    expect($records[0]['context'])->toMatchArray(['tenant_id' => 12, 'user_id' => null, 'run_id' => null])
        ->and($records[1]['context'])->toMatchArray(['tenant_id' => 12, 'user_id' => null, 'run_id' => null])
        ->and($records[2]['context'])->toMatchArray(['tenant_id' => 13, 'user_id' => null, 'run_id' => null])
        ->and($records[3]['context'])->toMatchArray(['tenant_id' => 90, 'user_id' => 80, 'run_id' => 70])
        ->and(file_get_contents($this->logFile))->not->toContain('synthetic-job-secret');
});

test('collection jobs log the internal run id and scoped container resets do not retain job IDs', function () {
    (new SetTenantContext)->handle(new CollectEngineRun(12, 34), fn () => Log::info('collect.test'));
    app()->forgetScopedInstances();
    dispatch_sync(new LoggingTenantJob(13));
    Log::info('worker.idle');

    $records = structuredLogRecords($this->logFile);
    expect($records[0]['context'])->toMatchArray(['tenant_id' => 12, 'user_id' => null, 'run_id' => 34])
        ->and($records[1]['context'])->toMatchArray(['tenant_id' => 13, 'user_id' => null, 'run_id' => null])
        ->and($records[2]['context'])->toMatchArray(['tenant_id' => null, 'user_id' => null, 'run_id' => null]);
});

test('scheduled tenant scopes retain the innermost failure ids without attributing them to the caller', function () {
    try {
        app(TenantContext::class)->run(21, fn () => app(TenantContext::class)->run(22, fn () => throw new RuntimeException('synthetic-scheduled-secret')));
    } catch (RuntimeException $failure) {
        report($failure);
    }
    Log::info('scheduler.idle');
    $records = structuredLogRecords($this->logFile);
    expect($records[0]['context']['tenant_id'])->toBe(22)
        ->and($records[1]['context']['tenant_id'])->toBeNull();
});

test('REST and MCP logs carry the selected tenant and actor without leaking into the next request', function (string $transport) {
    $world = MetricsWorld::create();
    $user = $world->tenant->owner;
    $user->switchTeam($world->tenant);
    app(TenantContext::class)->forget();
    $active = true;
    DB::listen(function ($query) use (&$active) {
        if ($active && (str_contains($query->sql, 'from "brands"') || str_contains($query->sql, 'from "prompts"'))) {
            Log::info('transport.probe');
        }
    });
    if ($transport === 'api') {
        $token = $user->createToken('logging-probe', ['read', 'tenant:'.$world->tenant->id]);
        $this->withToken($token->plainTextToken)->getJson('/api/v1/brands')->assertOk();
    } else {
        $token = McpTestClient::connect($user, $world->tenant);
        $result = McpTestClient::call($this, $token, 'get_prompt_results');
        expect($result['isError'])->toBeFalse();
    }
    $active = false;
    $records = array_values(array_filter(structuredLogRecords($this->logFile), fn ($record) => $record['message'] === 'transport.probe'));
    expect($records)->not->toBeEmpty();
    foreach ($records as $record) {
        expect($record['context'])->toMatchArray(['tenant_id' => $world->tenant->id, 'user_id' => $user->id, 'run_id' => null]);
    }
    expect(Log::sharedContext())->toMatchArray(['tenant_id' => null, 'user_id' => null, 'run_id' => null]);
})->with(['api', 'mcp']);

test('extraction failure hooks identify their tenant outside queue middleware', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $tenant = $user->ownedTeams()->firstOrFail();
    app(LogContext::class)->share(['tenant_id' => 123, 'user_id' => 456, 'run_id' => 789]);
    (new PollExtractionBatch($tenant->id, 999999))->failed(new RuntimeException('synthetic-secret'));
    $record = structuredLogRecords($this->logFile)[0];
    expect($record['context'])->toMatchArray(['tenant_id' => $tenant->id, 'user_id' => null, 'run_id' => null, 'extraction_job_id' => 999999])
        ->and(file_get_contents($this->logFile))->not->toContain('synthetic-secret');
});
