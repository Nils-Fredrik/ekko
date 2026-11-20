<?php

// T21-F1: a unique job dispatched inside a transaction while it is already queued must not abort the PostgreSQL
// transaction. The database cache store's own lock catches the unique violation, which PostgreSQL has already used
// to abort the transaction (SQLSTATE 25P02). Production uses the database cache (CACHE_STORE in
// infra/terraform/workloads.tf), so every unique job takes its lock through CollectionLocks::uniqueJobCache().

use App\Jobs\AggregateTenantDays;
use App\Jobs\CheckTenantFacts;
use App\Jobs\ProcessWorkSignal;
use App\Jobs\SubmitExtractionBatch;
use App\Jobs\TenantJob;
use App\Scheduling\PostgresLockStore;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** @return array<class-string, Closure(int): TenantJob> */
function uniqueTenantJobs(): array
{
    return [
        SubmitExtractionBatch::class => fn (int $tenantId) => new SubmitExtractionBatch($tenantId),
        AggregateTenantDays::class => fn (int $tenantId) => new AggregateTenantDays($tenantId, ['2026-11-19', '2026-11-20']),
        ProcessWorkSignal::class => fn (int $tenantId) => new ProcessWorkSignal($tenantId, 7, 'wake-'.$tenantId.'-7-1'),
        CheckTenantFacts::class => fn (int $tenantId) => new CheckTenantFacts($tenantId),
    ];
}

/** The database cache store on PostgreSQL, as in production; the tests otherwise use the array store. */
function useDatabaseCache(): void
{
    config(['cache.default' => 'database']);
    app()->forgetInstance('cache.store');
}

dataset('unique jobs', fn () => collect(uniqueTenantJobs())->map(fn (Closure $make) => [$make])->all());

it('queues a unique job twice inside a transaction on the PostgreSQL database cache without aborting it', function (Closure $make) {
    useDatabaseCache();
    Queue::fake();
    $job = $make(4711);

    DB::transaction(function () use ($make): void {
        dispatch($make(4711));
        // The job is already queued: the lock is held, and the second dispatch is dropped.
        dispatch($make(4711));

        expect(DB::scalar('select 1'))->toBe(1);
    });

    Queue::assertPushed($job::class, 1);
    expect(DB::table('cache_locks')->where('key', config('cache.prefix').UniqueLock::getKey($job))->count())->toBe(1);
})->with('unique jobs');

it('takes the lock of every unique job through the PostgreSQL-safe lock store', function () {
    useDatabaseCache();
    $unique = collect(glob(app_path('Jobs/*.php')))
        ->map(fn (string $path) => 'App\\Jobs\\'.Str::before(basename($path), '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, ShouldBeUnique::class))
        ->sort()->values()->all();

    // A new unique job is added to uniqueTenantJobs(), so the transaction test above covers it.
    expect($unique)->toBe(collect(uniqueTenantJobs())->keys()->sort()->values()->all());
    foreach (uniqueTenantJobs() as $make) {
        expect($make(4711)->uniqueVia()->getStore())->toBeInstanceOf(PostgresLockStore::class);
    }
});
