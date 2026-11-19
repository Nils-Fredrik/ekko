<?php

use App\Models\Team;
use App\Models\User;
use App\Models\WorkSignal;
use App\Scheduling\CloudRunAccessToken;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Work\CloudTasks\CloudTasksWorkPublisher;
use App\Work\Listeners\PublishQueueWakeOnWorkerStop;
use App\Work\NullWorkPublisher;
use App\Work\WorkKind;
use App\Work\WorkOutcome;
use App\Work\WorkProcessor;
use App\Work\WorkPublisher;
use App\Work\WorkSignals;
use App\Work\WorkWakeUp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\Work\CallbackWorkHandler;
use Tests\Support\Work\GoogleTokens;

// Targeted wake-ups through Cloud Tasks (docs/spec.md 7.1, ADR 0033, T40-F4): the publisher, the fixed authenticated
// routes, the bounded worker start, idempotent delivery and the hourly recovery check. No Google API is called: the
// Cloud Tasks API and the Cloud Run launcher are faked.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC'));
    config([
        'app.url' => 'https://ekko.example.test',
        'queue.default' => 'database',
        'ekko.work.publisher' => 'cloud_tasks',
        'ekko.work.retry_jitter_seconds' => 0,
        'ekko.work.lease_seconds' => 360,
        'ekko.work.recover_after_seconds' => 300,
        'ekko.work.wake.launch_cooldown_seconds' => 120,
        'ekko.work.cloud_tasks.project' => 'ekko-fixture',
        'ekko.work.cloud_tasks.location' => 'europe-west1',
        'ekko.work.cloud_tasks.queue' => 'ekko-work',
    ]);
    $this->tokens = new GoogleTokens;

    $access = Mockery::mock(CloudRunAccessToken::class);
    $access->shouldReceive('get')->andReturn('fixture-access-token');
    app()->instance(CloudRunAccessToken::class, $access);

    // Every created task, in order; the response for the next create can be set per test.
    $this->tasks = [];
    $this->taskResponses = [];
    Http::fake(['https://cloudtasks.googleapis.com/*' => function (HttpRequest $request) {
        $this->tasks[] = $request->data()['task'];

        return array_shift($this->taskResponses) ?? Http::response(['name' => $request->data()['task']['name']]);
    }]);

    // The Cloud Run API behind the real launcher: web may only list and start ekko-wake (ADR 0030).
    config(['collection.cloud_run.enabled' => true, 'collection.cloud_run.project' => 'ekko-fixture', 'collection.cloud_run.region' => 'europe-west1']);
    $this->launches = 0;
    $this->wakeActive = false;
    $this->wakeFails = false;
    Http::fake([
        'https://run.googleapis.com/*/executions*' => fn () => Http::response(['executions' => $this->wakeActive ? [['name' => 'running']] : []]),
        'https://run.googleapis.com/*:run' => function (HttpRequest $request) {
            if ($this->wakeFails) {
                return Http::response([], 500);
            }
            $this->launches++;

            return Http::response(['name' => 'operation']);
        },
    ]);
    app()->forgetInstance(WorkPublisher::class);
    app()->forgetScopedInstances();

    $this->tenant = Team::factory()->tenant()->create();
    $this->other = Team::factory()->tenant()->create();
    $this->inTenant = fn (Team $tenant, Closure $callback): mixed => app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], $callback));
    $this->signal = fn (Team $tenant, string $scope = '2026-11-19', ?CarbonImmutable $notBefore = null): WorkSignal => ($this->inTenant)($tenant, function () use ($scope, $notBefore) {
        app(WorkSignals::class)->signal(WorkKind::Aggregation, $scope, $notBefore);

        return WorkSignal::query()->where('scope', $scope)->sole();
    });
    $this->row = fn (Team $tenant, int $id): WorkSignal => ($this->inTenant)($tenant, fn () => WorkSignal::query()->findOrFail($id));
    $this->wake = fn (array $body, ?string $token = null) => $this->withToken($token ?? $this->tokens->tasks())->postJson('/internal/work/wake', $body);
    $this->queued = fn (): int => DB::table('jobs')->where('payload', 'like', '%ProcessWorkSignal%')->count();
    $this->work = function (): void {
        // A worker process: everything that can be taken now, then it stops (WorkerStopping). The memory limit is the
        // test process's, which a full suite run would otherwise exceed (exit code 12).
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096])->assertSuccessful();
    };
    $this->handled = [];
    config(['ekko.work.handlers.aggregation' => CallbackWorkHandler::class]);
    app()->instance(CallbackWorkHandler::class, new CallbackWorkHandler(function ($claim) {
        $this->handled[] = $claim->signalId;

        return WorkOutcome::done();
    }));
});

test('a signal is published after commit as a task with a hashed deterministic id, the fixed URL, OIDC and ids only', function () {
    $signal = ($this->signal)($this->tenant, '2026-11-19', CarbonImmutable::parse('2026-11-19 10:05:00', 'UTC'));

    expect($this->tasks)->toHaveCount(1);
    $task = $this->tasks[0];
    $key = 'ws-'.$this->tenant->id.'-'.$signal->id.'-0-'.CarbonImmutable::parse('2026-11-19 10:05:00', 'UTC')->getTimestampMs().'-signal';
    expect($task['name'])->toBe('projects/ekko-fixture/locations/europe-west1/queues/ekko-work/tasks/'.substr(hash('sha256', $key), 0, 16).'-'.$key)
        ->and($task['scheduleTime'])->toBe('2026-11-19T10:05:00.000Z')
        ->and($task['httpRequest']['url'])->toBe('https://ekko.example.test/internal/work/wake')
        ->and($task['httpRequest']['httpMethod'])->toBe('POST')
        ->and($task['httpRequest']['oidcToken'])->toBe(['serviceAccountEmail' => GoogleTokens::TASKS, 'audience' => GoogleTokens::TASKS_AUDIENCE])
        ->and(json_decode(base64_decode($task['httpRequest']['body']), true))->toBe(['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id]);
    Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://cloudtasks.googleapis.com/v2/projects/ekko-fixture/locations/europe-west1/queues/ekko-work/tasks')
        && $request->hasHeader('Authorization', 'Bearer fixture-access-token'));
});

test('coalesced signals share one task, and ALREADY_EXISTS from a repeated or retried create counts as published', function () {
    ($this->signal)($this->tenant);
    ($this->signal)($this->tenant);
    expect($this->tasks)->toHaveCount(1);

    // A second instance (another request or job) publishes the same wake-up again: the API refuses the duplicate.
    $this->taskResponses[] = Http::response(['error' => ['code' => 409, 'status' => 'ALREADY_EXISTS']], 409);
    $wakeUp = new WorkWakeUp($this->tenant->id, ($this->row)($this->tenant, WorkSignal::query()->withoutGlobalScopes()->value('id'))->id, 0, CarbonImmutable::now('UTC'));
    expect(app(CloudTasksWorkPublisher::class)->publish($wakeUp))->toBeNull()
        ->and($this->tasks[1]['name'])->toBe($this->tasks[0]['name']);

    // A transient failure is tried again; a create that went through although its answer was lost comes back as 409.
    $this->taskResponses = [Http::response([], 503), Http::response(['error' => ['status' => 'ALREADY_EXISTS']], 409)];
    app(CloudTasksWorkPublisher::class)->publish($wakeUp);
    expect($this->tasks)->toHaveCount(4)
        ->and(array_unique(array_column($this->tasks, 'name')))->toHaveCount(1);
});

test('a failed publish leaves the signal durable, and the recovery check publishes it again under a new id', function () {
    Log::spy();
    $this->taskResponses = [Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403)];
    $signal = ($this->signal)($this->tenant);

    expect(($this->row)($this->tenant, $signal->id)->isPending())->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'not published')
        && $context === ['tenant_id' => $this->tenant->id, 'work_signal_id' => $signal->id, 'error_type' => RuntimeException::class]);

    // Before the grace period nothing is republished; after it the hourly check does, with the sweep time in the id.
    $this->withToken($this->tokens->scheduler())->postJson('/internal/tick/work-recover')->assertNoContent();
    expect($this->tasks)->toHaveCount(1);
    $this->travel(301)->seconds();
    $this->withToken($this->tokens->scheduler())->postJson('/internal/tick/work-recover')->assertNoContent();
    expect($this->tasks)->toHaveCount(2)
        ->and($this->tasks[1]['name'])->not->toBe($this->tasks[0]['name'])
        ->and($this->tasks[1]['name'])->toContain('-recovery-')
        ->and($this->launches)->toBe(0);
});

test('a crash before the publish is recovered by the check; a crash after it is covered by the task itself', function () {
    // Before: the transaction committed, the process died before afterCommit ran, so no task exists.
    app()->instance(WorkPublisher::class, new NullWorkPublisher);
    app()->forgetScopedInstances();
    $signal = ($this->signal)($this->tenant);
    expect($this->tasks)->toBe([]);
    app()->forgetInstance(WorkPublisher::class);
    app()->forgetScopedInstances();
    $this->travel(301)->seconds();
    $this->withToken($this->tokens->scheduler())->postJson('/internal/tick/work-recover')->assertNoContent();
    expect($this->tasks)->toHaveCount(1);

    // After: the task exists and is delivered; the delivery reads the row and queues the work exactly once.
    ($this->wake)(['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id])->assertStatus(425);
    ($this->wake)(['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id])->assertStatus(425);
    expect(($this->queued)())->toBe(1);
});

test('the wake route accepts only the Cloud Tasks identity and audience', function (array $claims, bool $tamper) {
    $signal = ($this->signal)($this->tenant);
    $token = $this->tokens->tasks($claims);
    if ($tamper) {
        $parts = explode('.', $token);
        $parts[2] = 'invalid';
        $token = implode('.', $parts);
    }

    ($this->wake)(['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id], $token)->assertUnauthorized();
    $this->withToken($token)->postJson('/internal/work/queue-wake', ['queue' => 'default'])->assertUnauthorized();
    expect(($this->queued)())->toBe(0)->and($this->launches)->toBe(0);
})->with([
    'another service account' => [['email' => 'attacker@other.iam.gserviceaccount.com'], false],
    'the Scheduler identity' => [['email' => GoogleTokens::SCHEDULER], false],
    'the Scheduler audience' => [['aud' => GoogleTokens::SCHEDULER_AUDIENCE], false],
    'another audience' => [['aud' => 'https://ekko.example.test/other'], false],
    'unverified email' => [['email_verified' => false], false],
    'foreign issuer' => [['iss' => 'https://attacker.example.test'], false],
    'expired' => [['exp' => time() - 300], false],
    'bad signature' => [[], true],
]);

test('the Scheduler token is refused by the wake routes and the wake-up token by the Scheduler ticks', function () {
    $signal = ($this->signal)($this->tenant);
    ($this->wake)(['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id], $this->tokens->scheduler())->assertUnauthorized();
    $this->withToken($this->tokens->tasks())->postJson('/internal/tick/work-recover')->assertUnauthorized();
    $this->withToken($this->tokens->tasks())->postJson('/internal/tick/extraction-poll')->assertUnauthorized();
});

test('without a token, with a session or without configuration the wake routes are refused', function () {
    $signal = ($this->signal)($this->tenant);
    $body = ['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id];

    $this->postJson('/internal/work/wake', $body)->assertUnauthorized();
    $this->postJson('/internal/tick/work-recover')->assertUnauthorized();
    $this->actingAs(User::factory()->create(['is_platform_admin' => true]))->postJson('/internal/work/wake', $body)->assertUnauthorized();

    $token = $this->tokens->tasks();
    config(['ekko.work.cloud_tasks.audience' => null]);
    ($this->wake)($body, $token)->assertUnauthorized();
    expect(($this->queued)())->toBe(0);
});

test('malformed bodies are refused without effect', function (string $body) {
    $signal = ($this->signal)($this->tenant);
    $t = $this->tenant->id;
    $s = $signal->id;
    $body = match ($body) {
        'empty' => [],
        'tenant only' => ['tenant_id' => $t],
        'extra field' => ['tenant_id' => $t, 'signal_id' => $s, 'command' => 'migrate:fresh'],
        'strings' => ['tenant_id' => (string) $t, 'signal_id' => (string) $s],
        'negative' => ['tenant_id' => -$t, 'signal_id' => $s],
        'arrays' => ['tenant_id' => [$t], 'signal_id' => $s],
    };

    ($this->wake)($body)->assertStatus(422);
    expect(($this->queued)())->toBe(0)->and($this->launches)->toBe(0);
})->with(['empty', 'tenant only', 'extra field', 'strings', 'negative', 'arrays']);

test('another tenant\'s signal cannot be woken through a tenant id: it is not found and nothing changes', function () {
    $foreign = ($this->signal)($this->other);
    $before = ($this->row)($this->other, $foreign->id)->getAttributes();

    // Tenant A's id with tenant B's signal, and a signal id that does not exist.
    ($this->wake)(['tenant_id' => $this->tenant->id, 'signal_id' => $foreign->id])->assertNoContent();
    ($this->wake)(['tenant_id' => $this->tenant->id, 'signal_id' => $foreign->id + 1000])->assertNoContent();

    expect(($this->queued)())->toBe(0)
        ->and($this->launches)->toBe(0)
        ->and(($this->row)($this->other, $foreign->id)->getAttributes())->toBe($before);

    // The right pair is processed, under tenant B only.
    ($this->wake)(['tenant_id' => $this->other->id, 'signal_id' => $foreign->id])->assertStatus(425);
    ($this->work)();
    expect($this->handled)->toBe([$foreign->id])
        ->and(($this->row)($this->other, $foreign->id)->isPending())->toBeFalse();
});

test('closed tenants and personal teams are never processed', function () {
    $signal = ($this->signal)($this->tenant);
    $this->tenant->forceFill(['status' => 'closed'])->save();
    ($this->wake)(['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id])->assertNoContent();

    $personal = User::factory()->create()->currentTeam ?? Team::factory()->create(['personal_team' => true]);
    ($this->wake)(['tenant_id' => $personal->id, 'signal_id' => $signal->id])->assertNoContent();

    expect(($this->queued)())->toBe(0)->and($this->launches)->toBe(0);
});

test('duplicate and late deliveries queue one job, start the dispatcher once and settle when the work is done', function () {
    $signal = ($this->signal)($this->tenant);
    $body = ['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id];

    // No worker: the fixed dispatcher is woken once; the delivery stays open until a worker has taken the work.
    ($this->wake)($body)->assertStatus(425)->assertHeader('Retry-After', '15');
    ($this->wake)($body)->assertStatus(425);
    expect(($this->queued)())->toBe(1)->and($this->launches)->toBe(1);

    ($this->work)();
    expect($this->handled)->toBe([$signal->id]);

    // Retried and late deliveries now find the work done: settled, nothing queued or started.
    ($this->wake)($body)->assertNoContent();
    $this->travel(2)->hours();
    ($this->wake)($body)->assertNoContent();
    expect(($this->queued)())->toBe(0)->and($this->launches)->toBe(1)->and($this->handled)->toBe([$signal->id]);
});

test('an active worker takes the work without a new start', function () {
    $signal = ($this->signal)($this->tenant);
    // A job reserved a moment ago: a worker process is in the middle of it and pops the next one afterwards.
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => now()->subSeconds(30)->getTimestamp(), 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);

    ($this->wake)(['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id])->assertStatus(425);
    expect($this->launches)->toBe(0)->and(($this->queued)())->toBe(1);
});

test('a worker that exits right after a new start was refused does not strand the work', function () {
    $signal = ($this->signal)($this->tenant);
    $body = ['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id];

    // A dispatcher execution still counts as active, so no new one starts; the worker it saw exits without the job.
    $this->wakeActive = true;
    ($this->wake)($body)->assertStatus(425);
    expect($this->launches)->toBe(0);

    // Cloud Tasks comes back: within the cooldown nothing is tried, after it the dispatcher is woken.
    $this->wakeActive = false;
    $this->travel(30)->seconds();
    ($this->wake)($body)->assertStatus(425);
    expect($this->launches)->toBe(0);
    $this->travel(100)->seconds();
    ($this->wake)($body)->assertStatus(425);
    expect($this->launches)->toBe(1)->and(($this->queued)())->toBe(1);

    ($this->work)();
    ($this->wake)($body)->assertNoContent();
    expect($this->handled)->toBe([$signal->id]);
});

test('a worker crash under a lease is taken over once the lease expires', function () {
    $signal = ($this->signal)($this->tenant);
    $body = ['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id];

    // The worker leased the signal and died.
    ($this->inTenant)($this->tenant, fn () => app(WorkSignals::class)->claim($signal->id));
    ($this->wake)($body)->assertStatus(425);
    expect(($this->queued)())->toBe(0)->and($this->launches)->toBe(0);

    $this->travel(361)->seconds();
    ($this->wake)($body)->assertStatus(425);
    expect(($this->queued)())->toBe(1)->and($this->launches)->toBe(1);
    ($this->work)();
    expect($this->handled)->toBe([$signal->id])
        ->and(($this->row)($this->tenant, $signal->id)->isPending())->toBeFalse();
});

test('a delivery for a backoff, a deferral or a stopped signal settles without queueing, and the deferral is its own task', function () {
    app()->instance(CallbackWorkHandler::class, new CallbackWorkHandler(fn () => WorkOutcome::retryAt(CarbonImmutable::now('UTC')->addMinutes(5))));
    $signal = ($this->signal)($this->tenant);
    $body = ['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id];
    ($this->wake)($body)->assertStatus(425);
    ($this->work)();

    // The batch is still running: a future wake-up is registered for the next check, and the worker has exited.
    $deferral = collect($this->tasks)->last();
    expect($deferral['name'])->toEndWith('-defer')
        ->and($deferral['scheduleTime'])->toBe(CarbonImmutable::now('UTC')->addMinutes(5)->format('Y-m-d\TH:i:s.v\Z'))
        ->and(DB::table('jobs')->count())->toBe(0);
    ($this->wake)($body)->assertNoContent();

    // Stopped: no processing until a new signal or ekko:work:retry.
    DB::table('work_signals')->where('id', $signal->id)->update(['stopped_at' => now(), 'available_at' => now()->subMinute()]);
    ($this->wake)($body)->assertNoContent();
    expect(($this->queued)())->toBe(0);
});

test('a stopping worker registers a queue wake-up for delayed jobs, and the wake-up starts a worker only when they are due', function () {
    // A job released for later (a rate limit or a backoff), and no other work.
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => null, 'available_at' => now()->addMinutes(7)->getTimestamp(), 'created_at' => now()->getTimestamp()]);
    app(PublishQueueWakeOnWorkerStop::class)->handle(new WorkerStopping(0));
    app(PublishQueueWakeOnWorkerStop::class)->handle(new WorkerStopping(0));

    expect(array_unique(array_column($this->tasks, 'name')))->toHaveCount(1);
    $task = $this->tasks[0];
    expect($task['httpRequest']['url'])->toBe('https://ekko.example.test/internal/work/queue-wake')
        ->and($task['scheduleTime'])->toBe('2026-11-19T10:07:00.000Z')
        ->and(json_decode(base64_decode($task['httpRequest']['body']), true))->toBe(['queue' => 'default']);

    // Delivered early (or after the job moved further out): nothing to start, the next time is registered.
    $this->withToken($this->tokens->tasks())->postJson('/internal/work/queue-wake', ['queue' => 'default'])->assertNoContent();
    expect($this->launches)->toBe(0);

    $this->travel(7)->minutes();
    $this->withToken($this->tokens->tasks())->postJson('/internal/work/queue-wake', ['queue' => 'default'])->assertStatus(425);
    expect($this->launches)->toBe(1);

    $this->withToken($this->tokens->tasks())->postJson('/internal/work/queue-wake', ['other' => 1])->assertStatus(422);
});

test('the hourly recovery check starts nothing when there is no work', function () {
    $this->withToken($this->tokens->scheduler())->postJson('/internal/tick/work-recover')->assertNoContent();

    expect($this->tasks)->toBe([])->and($this->launches)->toBe(0);
});

test('the recovery check wakes the dispatcher for stranded queue jobs, but not while a worker is busy', function () {
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->getTimestamp()]);
    $this->withToken($this->tokens->scheduler())->postJson('/internal/tick/work-recover')->assertNoContent();
    expect($this->launches)->toBe(1);

    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => now()->subSeconds(10)->getTimestamp(), 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
    $this->travel(200)->seconds();
    $this->withToken($this->tokens->scheduler())->postJson('/internal/tick/work-recover')->assertNoContent();
    expect($this->launches)->toBe(1);
});

test('the dispatcher failing to start gives 503 and the next delivery may try again at once', function () {
    $signal = ($this->signal)($this->tenant);
    $this->wakeFails = true;
    ($this->wake)(['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id])->assertStatus(503);
    $this->wakeFails = false;

    ($this->wake)(['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id])->assertStatus(425);
    expect($this->launches)->toBe(1)->and(($this->queued)())->toBe(1);
});

test('a processor run from a delivery never exceeds one paid call per version: duplicate jobs find the work done', function () {
    $signal = ($this->signal)($this->tenant);

    // One delivery queues the processor job; a second processor run for the same signal (as from a duplicate
    // delivery) runs directly first, so the queued job then finds the work done.
    ($this->wake)(['tenant_id' => $this->tenant->id, 'signal_id' => $signal->id])->assertStatus(425);
    app(TenantContext::class)->run($this->tenant->id, fn () => app(WorkProcessor::class)->process($signal->id));
    ($this->work)();

    expect($this->handled)->toBe([$signal->id]);
});
