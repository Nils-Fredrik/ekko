<?php

use App\Models\Team;
use App\Models\WorkSignal;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Work\LeaseLost;
use App\Work\WorkKind;
use App\Work\WorkProcessor;
use App\Work\WorkPublisher;
use App\Work\WorkResult;
use App\Work\WorkSignals;
use App\Work\WorkWakeUp;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Work\RecordingWorkPublisher;

// Durable work signals with real commits and a second database connection (ADR 0032): the transactional test
// suites cannot show what another session sees, or that a wake-up waits for the real commit.

beforeEach(function () {
    expect(DB::transactionLevel())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC'));
    config([
        'ekko.work.recover_after_seconds' => 300,
        'database.connections.pgsql_concurrent' => config('database.connections.'.config('database.default')),
    ]);
    $this->publisher = new RecordingWorkPublisher;
    app()->instance(WorkPublisher::class, $this->publisher);
    app()->forgetScopedInstances();

    $this->tenant = Team::factory()->tenant()->create();
    app(TenantContext::class)->set($this->tenant->id);
    $this->rls = app(TenantRls::class);
    $this->signals = app(WorkSignals::class);
    $this->row = fn (): WorkSignal => $this->rls->run([$this->tenant->id], fn () => WorkSignal::query()->sole());

    // A second session, as another worker or web process would have.
    $this->otherSession = function (callable $callback) {
        $default = config('database.default');
        DB::setDefaultConnection('pgsql_concurrent');
        try {
            return $callback(new WorkSignals(app(TenantContext::class), $this->publisher, app('db')));
        } finally {
            DB::setDefaultConnection($default);
        }
    };
});

afterEach(function () {
    DB::disconnect('pgsql_concurrent');
});

test('a signal outside a transaction is refused', function () {
    expect(fn () => $this->signals->signal(WorkKind::Aggregation, '2026-11-19'))
        ->toThrow(LogicException::class, 'work_signal_needs_transaction');
});

test('the wake-up goes out after the real commit, and a real rollback leaves nothing', function () {
    expect(fn () => $this->rls->run([$this->tenant->id], function () {
        $this->signals->signal(WorkKind::Aggregation, '2026-11-18');

        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);

    expect($this->rls->run([$this->tenant->id], fn () => WorkSignal::query()->count()))->toBe(0)
        ->and($this->publisher->published)->toBe([]);

    $this->rls->run([$this->tenant->id], fn () => $this->signals->signal(WorkKind::Aggregation, '2026-11-19'));

    expect($this->publisher->levels)->toBe([0])
        ->and(($this->row)()->version)->toBe(1);
});

test('an uncommitted signal is invisible to another session until it commits', function () {
    $connection = DB::connection('pgsql_concurrent');
    ($this->otherSession)(function (WorkSignals $signals) use ($connection) {
        $connection->beginTransaction();
        $connection->statement('SET LOCAL ROLE '.TenantRls::roleName());
        $connection->select("SELECT set_config('app.tenant_ids', ?, true)", [(string) $this->tenant->id]);
        $signals->signal(WorkKind::Aggregation, '2026-11-19');
    });

    expect($this->rls->run([$this->tenant->id], fn () => WorkSignal::query()->count()))->toBe(0)
        ->and($this->publisher->published)->toBe([]);

    $connection->commit();

    expect(($this->row)()->version)->toBe(1)
        ->and($this->publisher->published)->toHaveCount(1);
});

test('a claim waits for an uncommitted signal on the same row instead of leasing around it', function () {
    $this->rls->run([$this->tenant->id], fn () => $this->signals->signal(WorkKind::Aggregation, '2026-11-19'));
    $id = ($this->row)()->id;

    $connection = DB::connection('pgsql_concurrent');
    ($this->otherSession)(function (WorkSignals $signals) use ($connection) {
        $connection->beginTransaction();
        $connection->statement('SET LOCAL ROLE '.TenantRls::roleName());
        $connection->select("SELECT set_config('app.tenant_ids', ?, true)", [(string) $this->tenant->id]);
        $signals->signal(WorkKind::Aggregation, '2026-11-19');
    });

    DB::statement("SET lock_timeout = '300ms'");
    try {
        expect(fn () => $this->rls->run([$this->tenant->id], fn () => $this->signals->claim($id)))
            ->toThrow(QueryException::class, 'lock timeout');
    } finally {
        DB::statement('RESET lock_timeout');
    }

    $connection->commit();
    $claim = $this->rls->run([$this->tenant->id], fn () => $this->signals->claim($id));

    expect($claim->version)->toBe(2);
});

test('signals committed by another session while a lease is held stay pending after the acknowledgement', function () {
    $this->rls->run([$this->tenant->id], fn () => $this->signals->signal(WorkKind::Aggregation, '2026-11-19'));
    $id = ($this->row)()->id;
    $claim = $this->rls->run([$this->tenant->id], fn () => $this->signals->claim($id));

    ($this->otherSession)(fn (WorkSignals $signals) => app(TenantRls::class)->run([$this->tenant->id], fn () => $signals->signal(WorkKind::Aggregation, '2026-11-19')));

    $this->rls->run([$this->tenant->id], fn () => $this->signals->acknowledge($claim));

    $row = ($this->row)();
    expect($row->version)->toBe(2)
        ->and($row->processed_version)->toBe(1)
        ->and($this->publisher->reasons())->toBe([WorkWakeUp::REASON_SIGNAL, WorkWakeUp::REASON_REMAINING]);
});

test('an expired lease is taken over by another session, and the first acknowledgement rolls back its own work', function () {
    $this->rls->run([$this->tenant->id], fn () => $this->signals->signal(WorkKind::Aggregation, '2026-11-19'));
    $id = ($this->row)()->id;
    $first = $this->rls->run([$this->tenant->id], fn () => $this->signals->claim($id));

    $this->travel(361)->seconds();
    $second = ($this->otherSession)(fn (WorkSignals $signals) => app(TenantRls::class)->run([$this->tenant->id], fn () => $signals->claim($id)));

    expect(fn () => $this->rls->run([$this->tenant->id], function () use ($first) {
        DB::table('work_signals')->where('id', $first->signalId)->update(['last_error' => 'written by the stale owner']);
        $this->signals->acknowledge($first);
    }))->toThrow(LeaseLost::class);

    $row = ($this->row)();
    expect($row->lease_token)->toBe($second->token)
        ->and($row->last_error)->toBeNull()
        ->and($row->processed_version)->toBe(0);
});

test('work committed while the wake-up fails is published by the recovery sweep', function () {
    $this->publisher->failing = true;
    $this->rls->run([$this->tenant->id], fn () => $this->signals->signal(WorkKind::UsageRollup, '2026-11'));
    $this->publisher->failing = false;
    app(TenantContext::class)->forget();

    expect($this->publisher->published)->toBe([]);

    $this->travel(301)->seconds();
    $this->artisan('ekko:work:recover')->assertSuccessful();

    expect($this->publisher->reasons())->toBe([WorkWakeUp::REASON_RECOVERY])
        ->and($this->publisher->levels)->toBe([0, 0]);
});

test('the processor refuses to run inside a transaction, where its lease would stay invisible', function () {
    expect(app(WorkProcessor::class)->process(1)->status)->toBe(WorkResult::IDLE);

    // Under the test runner one level is allowed for the transactional test case, so nest two here.
    expect(fn () => DB::transaction(fn () => DB::transaction(fn () => app(WorkProcessor::class)->process(1))))
        ->toThrow(LogicException::class, 'work_processor_must_not_run_in_a_transaction');
});
