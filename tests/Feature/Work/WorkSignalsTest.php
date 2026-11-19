<?php

use App\Jobs\ProcessWorkSignal;
use App\Models\Team;
use App\Models\WorkSignal;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Work\LeaseLost;
use App\Work\MissingWorkHandler;
use App\Work\NullWorkPublisher;
use App\Work\QueueWorkPublisher;
use App\Work\WorkClaim;
use App\Work\WorkKind;
use App\Work\WorkOutcome;
use App\Work\WorkProcessor;
use App\Work\WorkPublisher;
use App\Work\WorkResult;
use App\Work\WorkScope;
use App\Work\WorkSignals;
use App\Work\WorkWakeUp;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\TenantRecord;
use Tests\Support\Work\CallbackWorkHandler;
use Tests\Support\Work\RecordingWorkPublisher;

// Durable registration of event-driven work (docs/spec.md 7.1, ADR 0032, T40-F2).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC'));
    config([
        'ekko.work.retry_base_seconds' => 30,
        'ekko.work.retry_max_seconds' => 3600,
        'ekko.work.retry_jitter_seconds' => 0,
        'ekko.work.lease_seconds' => 360,
        'ekko.work.recover_after_seconds' => 300,
    ]);
    $this->publisher = new RecordingWorkPublisher;
    app()->instance(WorkPublisher::class, $this->publisher);
    app()->forgetScopedInstances();

    $this->tenant = Team::factory()->tenant()->create(['timezone' => 'Europe/Oslo']);
    app(TenantContext::class)->set($this->tenant->id);
    $this->rls = app(TenantRls::class);
    $this->signals = app(WorkSignals::class);

    // Runs a callback the way a job does: in the tenant's row-level security transaction.
    $this->inTenant = fn (callable $callback) => $this->rls->run([$this->tenant->id], $callback);
    $this->signal = fn (WorkKind $kind = WorkKind::Aggregation, string $scope = '2026-11-19', ?CarbonImmutable $notBefore = null) => ($this->inTenant)(fn () => $this->signals->signal($kind, $scope, $notBefore));
    $this->row = fn (): WorkSignal => ($this->inTenant)(fn () => WorkSignal::query()->sole());
    $this->claim = fn (int $id): ?WorkClaim => ($this->inTenant)(fn () => $this->signals->claim($id));

    $this->handler = function (Closure $callback): CallbackWorkHandler {
        $handler = new CallbackWorkHandler($callback);
        app()->instance(CallbackWorkHandler::class, $handler);
        config(['ekko.work.handlers.aggregation' => CallbackWorkHandler::class]);

        return $handler;
    };
});

test('a signal is registered in the transaction and published only after commit', function () {
    ($this->inTenant)(function () {
        $this->signals->signal(WorkKind::Aggregation, '2026-11-19');

        expect($this->publisher->published)->toBe([])
            ->and(WorkSignal::query()->count())->toBe(1);
    });

    $row = ($this->row)();
    expect($row->kind)->toBe(WorkKind::Aggregation)
        ->and($row->scope)->toBe('2026-11-19')
        ->and($row->version)->toBe(1)
        ->and($row->processed_version)->toBe(0)
        ->and($row->isPending())->toBeTrue()
        ->and($row->pending_since?->toIso8601String())->toBe('2026-11-19T10:00:00+00:00')
        ->and($this->publisher->published)->toHaveCount(1);

    $wakeUp = $this->publisher->published[0];
    expect($wakeUp->tenantId)->toBe($this->tenant->id)
        ->and($wakeUp->signalId)->toBe($row->id)
        ->and($wakeUp->reason)->toBe(WorkWakeUp::REASON_SIGNAL)
        ->and($wakeUp->payload())->toBe(['tenant_id' => $this->tenant->id, 'signal_id' => $row->id])
        ->and($wakeUp->key())->toBe('ws-'.$this->tenant->id.'-'.$row->id.'-0-'.CarbonImmutable::now()->getTimestamp())
        ->and($wakeUp->key())->toMatch('/^[A-Za-z0-9_-]{1,500}$/');
});

test('a rolled back transaction leaves neither a signal nor a published wake-up', function () {
    TenantRecord::createTable();

    expect(fn () => ($this->inTenant)(function () {
        TenantRecord::query()->create(['name' => 'data change']);
        $this->signals->signal(WorkKind::Aggregation, '2026-11-19');

        throw new RuntimeException('the data change failed');
    }))->toThrow(RuntimeException::class);

    expect(($this->inTenant)(fn () => [WorkSignal::query()->count(), TenantRecord::query()->count()]))->toBe([0, 0])
        ->and($this->publisher->published)->toBe([]);
});

test('a rolled back savepoint drops its signal and wake-up, while the outer transaction commits', function () {
    ($this->inTenant)(function () {
        $this->signals->signal(WorkKind::Aggregation, '2026-11-18');

        try {
            DB::transaction(function () {
                $this->signals->signal(WorkKind::Aggregation, '2026-11-19');

                throw new RuntimeException('inner failure');
            });
        } catch (RuntimeException) {
        }
    });

    expect(($this->inTenant)(fn () => WorkSignal::query()->pluck('scope')->all()))->toBe(['2026-11-18'])
        ->and($this->publisher->published)->toHaveCount(1);
});

test('duplicate signals coalesce into one row, one version per signal, and one wake-up', function () {
    ($this->inTenant)(function () {
        foreach (range(1, 3) as $ignored) {
            $this->signals->signal(WorkKind::Aggregation, '2026-11-19');
        }
    });
    ($this->signal)();
    ($this->signal)();

    $row = ($this->row)();
    expect($row->version)->toBe(5)
        ->and($row->processed_version)->toBe(0)
        ->and(collect($this->publisher->published)->map->key()->unique()->all())->toHaveCount(1)
        ->and($this->publisher->published)->toHaveCount(1);
});

test('each kind and scope is its own signal', function () {
    ($this->signal)(WorkKind::Aggregation, '2026-11-19');
    ($this->signal)(WorkKind::Aggregation, '2026-11-18');
    ($this->signal)(WorkKind::Extraction, WorkScope::runBatch(12));
    ($this->signal)(WorkKind::Extraction, WorkScope::TENANT);
    ($this->signal)(WorkKind::UsageRollup, WorkScope::month(CarbonImmutable::parse('2026-11-30 23:30:00', 'UTC')));

    expect(($this->inTenant)(fn () => WorkSignal::query()->orderBy('id')->get()->map(fn (WorkSignal $row) => $row->kind->value.' '.$row->scope)->all()))
        ->toBe(['aggregation 2026-11-19', 'aggregation 2026-11-18', 'extraction run_batch:12', 'extraction tenant', 'usage_rollup 2026-11']);
});

test('the local date follows the tenant timezone across midnight', function () {
    $collectedAt = CarbonImmutable::parse('2026-11-30 23:30:00', 'UTC');

    expect(WorkScope::localDate($collectedAt, 'Europe/Oslo'))->toBe('2026-12-01')
        ->and(WorkScope::localDate($collectedAt, 'UTC'))->toBe('2026-11-30')
        ->and(WorkScope::month($collectedAt))->toBe('2026-11');
});

test('scopes accept internal identifiers only, in the service and in the database', function (WorkKind $kind, string $scope) {
    expect(fn () => ($this->signal)($kind, $scope))->toThrow(InvalidArgumentException::class, 'invalid_work_scope');
})->with([
    'free text' => [WorkKind::Extraction, 'Acme AS svarte'],
    'aggregation month' => [WorkKind::Aggregation, '2026-11'],
    'impossible date' => [WorkKind::Aggregation, '2026-02-30'],
    'usage day' => [WorkKind::UsageRollup, '2026-11-19'],
    'zero id' => [WorkKind::Extraction, 'run_batch:0'],
    'unknown group' => [WorkKind::Extraction, 'prompt:12'],
]);

test('the database refuses free text in a scope even past the service', function () {
    expect(fn () => ($this->inTenant)(fn () => DB::table('work_signals')->insert([
        'tenant_id' => $this->tenant->id, 'kind' => 'aggregation', 'scope' => 'kunden sitt svar',
        'available_at' => now(), 'last_signaled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'work_signals_scope');
});

test('a signal without a tenant in the context is refused', function () {
    app(TenantContext::class)->forget();

    expect(fn () => DB::transaction(fn () => app(WorkSignals::class)->signal(WorkKind::Aggregation, '2026-11-19')))
        ->toThrow(MissingTenantContextException::class);
});

test('a processor acknowledges only the version it leased; signals during processing stay pending', function () {
    ($this->signal)();
    $id = ($this->row)()->id;
    $claim = ($this->claim)($id);
    expect($claim->version)->toBe(1)->and($claim->attempt)->toBe(1);

    // Two signals arrive while the processor works. They raise the version, and need no wake-up of their own.
    ($this->signal)();
    ($this->signal)();
    expect($this->publisher->published)->toHaveCount(1);

    ($this->inTenant)(fn () => $this->signals->acknowledge($claim));

    $row = ($this->row)();
    expect($row->version)->toBe(3)
        ->and($row->processed_version)->toBe(1)
        ->and($row->isPending())->toBeTrue()
        ->and($row->lease_token)->toBeNull()
        ->and($row->attempts)->toBe(0)
        ->and($this->publisher->reasons())->toBe([WorkWakeUp::REASON_SIGNAL, WorkWakeUp::REASON_REMAINING])
        ->and($this->publisher->published[1]->epoch)->toBe(1);

    $next = ($this->claim)($id);
    expect($next->version)->toBe(3);
    ($this->inTenant)(fn () => $this->signals->acknowledge($next));

    $row = ($this->row)();
    expect($row->processed_version)->toBe(3)
        ->and($row->isPending())->toBeFalse()
        ->and($row->pending_since)->toBeNull()
        ->and($row->processed_at?->toIso8601String())->toBe('2026-11-19T10:00:00+00:00')
        ->and($this->publisher->published)->toHaveCount(2)
        ->and(($this->claim)($id))->toBeNull();
});

test('a new signal after processing starts a new epoch with a new wake-up key', function () {
    ($this->signal)();
    $id = ($this->row)()->id;
    $claim = ($this->claim)($id);
    ($this->inTenant)(fn () => $this->signals->acknowledge($claim));

    $this->travel(5)->seconds();
    ($this->signal)();

    $keys = collect($this->publisher->published)->map->key();
    expect($keys)->toHaveCount(2)
        ->and($keys->unique())->toHaveCount(2)
        ->and($this->publisher->published[1]->epoch)->toBe(1)
        ->and(($this->row)()->pending_since?->toIso8601String())->toBe('2026-11-19T10:00:05+00:00');
});

test('a live lease cannot be claimed twice, and a duplicate delivery is told when to come back', function () {
    ($this->signal)();
    $id = ($this->row)()->id;

    expect(($this->claim)($id))->not->toBeNull()
        ->and(($this->claim)($id))->toBeNull()
        ->and(($this->inTenant)(fn () => $this->signals->busyUntil($id))?->toIso8601String())->toBe('2026-11-19T10:06:00+00:00');
});

test('an expired lease is taken over, and the old token acknowledges nothing', function () {
    Log::spy();
    ($this->signal)();
    $id = ($this->row)()->id;
    $first = ($this->claim)($id);

    $this->travel(361)->seconds();
    $second = ($this->claim)($id);
    expect($second)->not->toBeNull()
        ->and($second->token)->not->toBe($first->token)
        ->and($second->attempt)->toBe(2);

    expect(fn () => ($this->inTenant)(fn () => $this->signals->acknowledge($first)))->toThrow(LeaseLost::class)
        ->and(fn () => ($this->inTenant)(fn () => $this->signals->defer($first, now()->toImmutable()->addMinute())))->toThrow(LeaseLost::class)
        ->and(($this->inTenant)(fn () => $this->signals->fail($first, new RuntimeException('late'))))->toBeFalse()
        ->and(($this->row)()->lease_token)->toBe($second->token);

    ($this->inTenant)(fn () => $this->signals->acknowledge($second));
    expect(($this->row)()->isPending())->toBeFalse();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'taken over'))->once();
});

test('the processor commits handler writes and acknowledgement together', function () {
    TenantRecord::createTable();
    $handler = ($this->handler)(function (WorkClaim $claim) {
        TenantRecord::query()->create(['name' => 'aggregated '.$claim->scope]);

        return WorkOutcome::done();
    });
    ($this->signal)();

    $result = app(WorkProcessor::class)->process(($this->row)()->id);

    expect($result->status)->toBe(WorkResult::PROCESSED)
        ->and($handler->claims)->toHaveCount(1)
        ->and($handler->claims[0]->scope)->toBe('2026-11-19')
        ->and(($this->inTenant)(fn () => TenantRecord::query()->pluck('name')->all()))->toBe(['aggregated 2026-11-19'])
        ->and(($this->row)()->isPending())->toBeFalse();
});

test('a failed attempt rolls back the handler writes, keeps the signal and retries with backoff', function () {
    Log::spy();
    TenantRecord::createTable();
    $fail = true;
    ($this->handler)(function () use (&$fail) {
        TenantRecord::query()->create(['name' => 'half done']);
        if ($fail) {
            throw new RuntimeException('provider said: secret customer text');
        }

        return WorkOutcome::done();
    });
    ($this->signal)();
    $id = ($this->row)()->id;

    expect(app(WorkProcessor::class)->process($id)->status)->toBe(WorkResult::FAILED);

    $row = ($this->row)();
    expect(($this->inTenant)(fn () => TenantRecord::query()->count()))->toBe(0)
        ->and($row->isPending())->toBeTrue()
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toBe('RuntimeException')
        ->and($row->lease_token)->toBeNull()
        ->and($row->available_at->toIso8601String())->toBe('2026-11-19T10:00:30+00:00')
        ->and($this->publisher->reasons())->toBe([WorkWakeUp::REASON_SIGNAL, WorkWakeUp::REASON_RETRY])
        ->and($this->publisher->published[1]->notBefore->toIso8601String())->toBe('2026-11-19T10:00:30+00:00');

    // Neither a delivery before the backoff nor a new signal shortens it.
    ($this->signal)();
    $result = app(WorkProcessor::class)->process($id);
    expect($result->status)->toBe(WorkResult::BUSY)
        ->and($result->busyUntil?->toIso8601String())->toBe('2026-11-19T10:00:30+00:00')
        ->and($this->publisher->published)->toHaveCount(2);

    // The second failure doubles the wait.
    $this->travel(30)->seconds();
    expect(app(WorkProcessor::class)->process($id)->status)->toBe(WorkResult::FAILED)
        ->and(($this->row)()->available_at->toIso8601String())->toBe('2026-11-19T10:01:30+00:00');

    $fail = false;
    $this->travel(60)->seconds();
    expect(app(WorkProcessor::class)->process($id)->status)->toBe(WorkResult::PROCESSED);

    $row = ($this->row)();
    expect($row->isPending())->toBeFalse()
        ->and($row->attempts)->toBe(0)
        ->and($row->last_error)->toBeNull()
        ->and(($this->inTenant)(fn () => TenantRecord::query()->count()))->toBe(1);

    // Only the exception class is logged, never its message.
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'backoff')
        && $context['error_type'] === RuntimeException::class
        && ! str_contains(json_encode($context), 'secret'))->twice();
});

test('the backoff grows exponentially up to the cap and logs an error from the alert threshold', function () {
    Log::spy();
    config(['ekko.work.retry_max_seconds' => 100, 'ekko.work.alert_after_attempts' => 3]);
    ($this->handler)(fn () => throw new RuntimeException('down'));
    ($this->signal)();
    $id = ($this->row)()->id;

    $waits = [];
    foreach (range(1, 4) as $ignored) {
        app(WorkProcessor::class)->process($id);
        $waits[] = (int) CarbonImmutable::now()->diffInSeconds(($this->row)()->available_at);
        $this->travelTo(($this->row)()->available_at);
    }

    expect($waits)->toBe([30, 60, 100, 100])
        ->and(($this->row)()->attempts)->toBe(4);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'Work signal keeps failing.')->twice();
});

test('a handler can defer without a failure, for an external batch that is still running', function () {
    ($this->handler)(fn () => WorkOutcome::retryAt(CarbonImmutable::now()->addMinutes(2)));
    ($this->signal)();
    $id = ($this->row)()->id;

    expect(app(WorkProcessor::class)->process($id)->status)->toBe(WorkResult::DEFERRED);

    $row = ($this->row)();
    expect($row->isPending())->toBeTrue()
        ->and($row->attempts)->toBe(0)
        ->and($row->last_error)->toBeNull()
        ->and($row->available_at->toIso8601String())->toBe('2026-11-19T10:02:00+00:00')
        ->and($this->publisher->reasons())->toBe([WorkWakeUp::REASON_SIGNAL, WorkWakeUp::REASON_DEFER])
        ->and($this->publisher->published[1]->notBefore->toIso8601String())->toBe('2026-11-19T10:02:00+00:00')
        ->and(app(WorkProcessor::class)->process($id)->status)->toBe(WorkResult::BUSY);
});

test('a lost lease rolls back the handler writes and leaves the signal to the new owner', function () {
    TenantRecord::createTable();
    ($this->signal)();
    $id = ($this->row)()->id;
    ($this->handler)(function () use ($id) {
        TenantRecord::query()->create(['name' => 'stale work']);
        // The lease runs out while the handler works, and another processor takes the signal.
        $this->travel(400)->seconds();
        expect($this->signals->claim($id))->not->toBeNull();

        return WorkOutcome::done();
    });

    expect(app(WorkProcessor::class)->process($id)->status)->toBe(WorkResult::LEASE_LOST)
        ->and(($this->inTenant)(fn () => TenantRecord::query()->count()))->toBe(0);
});

test('a kind without a handler stays pending as a failure instead of being acknowledged', function () {
    config(['ekko.work.handlers.aggregation' => null]);
    ($this->signal)();

    expect(app(WorkProcessor::class)->process(($this->row)()->id)->status)->toBe(WorkResult::FAILED)
        ->and(($this->row)()->last_error)->toBe(class_basename(MissingWorkHandler::class))
        ->and(($this->row)()->isPending())->toBeTrue();
});

test('a closed tenant is not processed', function () {
    $handler = ($this->handler)(fn () => WorkOutcome::done());
    ($this->signal)();
    $this->tenant->forceFill(['status' => 'closed'])->save();

    expect(app(WorkProcessor::class)->process(($this->row)()->id)->status)->toBe(WorkResult::IDLE)
        ->and($handler->claims)->toBe([])
        ->and(($this->row)()->lease_token)->toBeNull();
});

test('a failed publish loses no work, and the recovery sweep wakes it again', function () {
    Log::spy();
    $this->publisher->failing = true;
    ($this->signal)();

    // The data change committed; only the wake-up is missing.
    expect(($this->row)()->isPending())->toBeTrue()
        ->and($this->publisher->published)->toBe([]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'not published'))->once();

    $this->publisher->failing = false;
    app(TenantContext::class)->forget();
    $this->artisan('ekko:work:recover')->assertSuccessful();
    expect($this->publisher->published)->toBe([]);

    $this->travel(301)->seconds();
    $this->artisan('ekko:work:recover')->assertSuccessful();
    expect($this->publisher->reasons())->toBe([WorkWakeUp::REASON_RECOVERY])
        ->and($this->publisher->published[0]->tenantId)->toBe($this->tenant->id)
        ->and($this->publisher->published[0]->key())->toEndWith('-r'.CarbonImmutable::now()->getTimestamp());
});

test('a crash between commit and publish loses no work', function () {
    // A process that dies right after commit never runs its after-commit callbacks: the same as publishing nothing.
    app()->instance(WorkPublisher::class, new NullWorkPublisher);
    app()->forgetScopedInstances();
    app(TenantContext::class)->set($this->tenant->id);
    ($this->inTenant)(fn () => app(WorkSignals::class)->signal(WorkKind::Aggregation, '2026-11-19'));

    app()->instance(WorkPublisher::class, $this->publisher);
    app()->forgetScopedInstances();
    app(TenantContext::class)->forget();
    $this->travel(301)->seconds();
    $this->artisan('ekko:work:recover')->assertSuccessful();

    expect($this->publisher->reasons())->toBe([WorkWakeUp::REASON_RECOVERY]);

    app(TenantContext::class)->set($this->tenant->id);
    ($this->handler)(fn () => WorkOutcome::done());
    expect(app(WorkProcessor::class)->process($this->publisher->published[0]->signalId)->status)->toBe(WorkResult::PROCESSED);
});

test('a crash during processing is recovered after the lease expires', function () {
    ($this->signal)();
    $id = ($this->row)()->id;
    // The processor claimed and then died: no acknowledgement, no failure record.
    ($this->claim)($id);
    app(TenantContext::class)->forget();

    $this->travel(301)->seconds();
    $this->artisan('ekko:work:recover')->assertSuccessful();
    expect($this->publisher->published)->toHaveCount(1);

    $this->travel(60)->seconds();
    $this->artisan('ekko:work:recover')->assertSuccessful();
    expect($this->publisher->reasons())->toBe([WorkWakeUp::REASON_SIGNAL, WorkWakeUp::REASON_RECOVERY]);

    app(TenantContext::class)->set($this->tenant->id);
    ($this->handler)(fn () => WorkOutcome::done());
    expect(app(WorkProcessor::class)->process($id)->status)->toBe(WorkResult::PROCESSED)
        ->and(($this->row)()->isPending())->toBeFalse();
});

test('a signal that is not yet due is neither claimed nor recovered early', function () {
    ($this->signal)(WorkKind::Extraction, WorkScope::runBatch(4), CarbonImmutable::now()->addMinutes(10));
    $id = ($this->row)()->id;

    expect($this->publisher->published[0]->notBefore->toIso8601String())->toBe('2026-11-19T10:10:00+00:00')
        ->and(($this->claim)($id))->toBeNull();

    app(TenantContext::class)->forget();
    $this->travel(9)->minutes();
    $this->artisan('ekko:work:recover')->assertSuccessful();
    expect($this->publisher->published)->toHaveCount(1);

    // An earlier signal for the same scope brings it forward.
    app(TenantContext::class)->set($this->tenant->id);
    ($this->signal)(WorkKind::Extraction, WorkScope::runBatch(4));
    expect(($this->row)()->available_at->toIso8601String())->toBe('2026-11-19T10:09:00+00:00')
        ->and(($this->claim)($id))->not->toBeNull();
});

test('the queue publisher queues a unique ProcessWorkSignal per wake-up key, delayed until due', function () {
    Queue::fake();
    $now = CarbonImmutable::now();
    $publisher = new QueueWorkPublisher;

    $publisher->publish(new WorkWakeUp($this->tenant->id, 7, 0, $now));
    $publisher->publish(new WorkWakeUp($this->tenant->id, 8, 2, $now->addMinutes(5), WorkWakeUp::REASON_RETRY));

    Queue::assertPushed(ProcessWorkSignal::class, 2);
    Queue::assertPushed(ProcessWorkSignal::class, fn (ProcessWorkSignal $job) => $job->signalId === 7 && $job->delay === null
        && $job->tenantId() === $this->tenant->id && $job->uniqueId() === 'work:ws-'.$this->tenant->id.'-7-0-'.$now->getTimestamp());
    Queue::assertPushed(ProcessWorkSignal::class, fn (ProcessWorkSignal $job) => $job->signalId === 8 && $job->delay !== null);
});

test('ProcessWorkSignal runs in the tenant context and releases itself while the signal is busy', function () {
    $handler = ($this->handler)(fn () => WorkOutcome::done());
    ($this->signal)();
    $id = ($this->row)()->id;
    ($this->claim)($id);
    app(TenantContext::class)->forget();

    $busy = (new ProcessWorkSignal($this->tenant->id, $id, 'k'))->withFakeQueueInteractions();
    app(TenantContext::class)->run($this->tenant->id, fn () => $busy->handle(app(WorkProcessor::class)));
    $busy->assertReleased(361);

    $this->travel(361)->seconds();
    dispatch_sync(new ProcessWorkSignal($this->tenant->id, $id, 'k'));

    app(TenantContext::class)->set($this->tenant->id);
    expect($handler->claims)->toHaveCount(1)
        ->and(($this->row)()->isPending())->toBeFalse()
        ->and((new ProcessWorkSignal($this->tenant->id, $id, 'k'))->middleware())->toHaveCount(1);
});
