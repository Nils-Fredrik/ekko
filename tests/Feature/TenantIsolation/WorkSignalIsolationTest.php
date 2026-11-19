<?php

use App\Jobs\ProcessWorkSignal;
use App\Jobs\TenantJob;
use App\Models\Team;
use App\Models\WorkSignal;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use App\Work\LeaseLost;
use App\Work\WorkClaim;
use App\Work\WorkKind;
use App\Work\WorkOutcome;
use App\Work\WorkPublisher;
use App\Work\WorkSignals;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Work\CallbackWorkHandler;
use Tests\Support\Work\RecordingWorkPublisher;

// Durable work signals (ADR 0032) never cross tenants: two tenants cannot read, change, claim or acknowledge each
// other's work, in the application (BelongsToTenant) or in the database (FORCE RLS as the ordinary role).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC'));
    $this->publisher = new RecordingWorkPublisher;
    app()->instance(WorkPublisher::class, $this->publisher);
    app()->forgetScopedInstances();

    $this->a = Team::factory()->tenant()->create();
    $this->b = Team::factory()->tenant()->create();
    $this->as = fn (Team $tenant, callable $callback) => app(TenantContext::class)->run($tenant->id, fn () => app(TenantRls::class)->run([$tenant->id], $callback));

    foreach ([$this->a, $this->b] as $tenant) {
        ($this->as)($tenant, fn () => app(WorkSignals::class)->signal(WorkKind::Aggregation, '2026-11-19'));
    }
    $this->idA = ($this->as)($this->a, fn () => WorkSignal::query()->sole()->id);
    $this->idB = ($this->as)($this->b, fn () => WorkSignal::query()->sole()->id);
});

test('the same kind and scope are separate signals per tenant', function () {
    ($this->as)($this->a, fn () => app(WorkSignals::class)->signal(WorkKind::Aggregation, '2026-11-19'));

    expect($this->idA)->not->toBe($this->idB)
        ->and(($this->as)($this->a, fn () => WorkSignal::query()->sole()->version))->toBe(2)
        ->and(($this->as)($this->b, fn () => WorkSignal::query()->sole()->version))->toBe(1)
        ->and(collect($this->publisher->published)->map(fn ($wakeUp) => [$wakeUp->tenantId, $wakeUp->signalId])->all())
        ->toBe([[$this->a->id, $this->idA], [$this->b->id, $this->idB]]);
});

test('a tenant cannot read the other tenant\'s signals, also without the global scope and in raw SQL', function () {
    $seen = ($this->as)($this->a, fn () => [
        'scoped' => WorkSignal::query()->pluck('id')->all(),
        'unscoped' => WorkSignal::withoutGlobalScope(TenantScope::class)->pluck('id')->all(),
        'raw' => DB::table('work_signals')->pluck('id')->all(),
        'raw_other' => DB::table('work_signals')->where('tenant_id', $this->b->id)->count(),
    ]);

    expect($seen)->toBe(['scoped' => [$this->idA], 'unscoped' => [$this->idA], 'raw' => [$this->idA], 'raw_other' => 0]);
});

test('a tenant cannot write a signal into the other tenant or move one there', function () {
    expect(fn () => ($this->as)($this->a, fn () => DB::table('work_signals')->insert([
        'tenant_id' => $this->b->id, 'kind' => 'aggregation', 'scope' => '2026-11-18',
        'available_at' => now(), 'last_signaled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'row-level security');

    expect(($this->as)($this->a, fn () => DB::table('work_signals')->where('id', $this->idB)->update(['processed_version' => 1])))->toBe(0)
        ->and(fn () => ($this->as)($this->a, fn () => DB::table('work_signals')->where('id', $this->idA)->update(['tenant_id' => $this->b->id])))
        ->toThrow(QueryException::class, 'row-level security')
        ->and(($this->as)($this->b, fn () => WorkSignal::query()->sole()->processed_version))->toBe(0);
});

test('a tenant cannot claim, acknowledge, defer or fail the other tenant\'s signal', function () {
    expect(($this->as)($this->a, fn () => app(WorkSignals::class)->claim($this->idB)))->toBeNull()
        ->and(($this->as)($this->a, fn () => app(WorkSignals::class)->busyUntil($this->idB)))->toBeNull();

    $claimB = ($this->as)($this->b, fn () => app(WorkSignals::class)->claim($this->idB));
    // The claim of B, replayed in A's context: the same token, but neither layer lets it through.
    $forged = new WorkClaim($this->a->id, $claimB->signalId, $claimB->kind, $claimB->scope, $claimB->version, $claimB->token, $claimB->attempt, $claimB->leaseExpiresAt);

    foreach ([$claimB, $forged] as $claim) {
        expect(fn () => ($this->as)($this->a, fn () => app(WorkSignals::class)->acknowledge($claim)))->toThrow(LeaseLost::class)
            ->and(fn () => ($this->as)($this->a, fn () => app(WorkSignals::class)->defer($claim, CarbonImmutable::now()->addHour())))->toThrow(LeaseLost::class)
            ->and(($this->as)($this->a, fn () => app(WorkSignals::class)->fail($claim, new RuntimeException('x'))))->toBeFalse();
    }

    $rowB = ($this->as)($this->b, fn () => WorkSignal::query()->sole());
    expect($rowB->lease_token)->toBe($claimB->token)
        ->and($rowB->processed_version)->toBe(0)
        ->and($rowB->attempts)->toBe(1);
});

test('a processing job for one tenant cannot process the other tenant\'s signal', function () {
    $handler = new CallbackWorkHandler(fn () => WorkOutcome::done());
    app()->instance(CallbackWorkHandler::class, $handler);
    config(['ekko.work.handlers.aggregation' => CallbackWorkHandler::class]);

    dispatch_sync(new ProcessWorkSignal($this->a->id, $this->idB, 'forged'));

    expect($handler->claims)->toBe([])
        ->and(($this->as)($this->b, fn () => WorkSignal::query()->sole()->isPending()))->toBeTrue();

    dispatch_sync(new ProcessWorkSignal($this->b->id, $this->idB, 'own'));

    expect($handler->claims)->toHaveCount(1)
        ->and($handler->claims[0]->tenantId)->toBe($this->b->id)
        ->and(($this->as)($this->b, fn () => WorkSignal::query()->sole()->isPending()))->toBeFalse()
        ->and(($this->as)($this->a, fn () => WorkSignal::query()->sole()->isPending()))->toBeTrue()
        ->and(is_subclass_of(ProcessWorkSignal::class, TenantJob::class))->toBeTrue();
});

test('the recovery sweep wakes every tenant\'s stranded work under that tenant only', function () {
    $this->travel(301)->seconds();
    $this->artisan('ekko:work:recover')->assertSuccessful();

    $recovered = collect($this->publisher->published)->where('reason', 'recovery')
        ->map(fn ($wakeUp) => [$wakeUp->tenantId, $wakeUp->signalId])->values()->all();

    expect($recovered)->toBe([[$this->a->id, $this->idA], [$this->b->id, $this->idB]]);
});

test('the work_signals table forces row-level security for the ordinary role', function () {
    $table = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = 'work_signals'");
    $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?', [TenantRls::roleName()]);

    expect($table->relrowsecurity)->toBeTrue()
        ->and($table->relforcerowsecurity)->toBeTrue()
        ->and($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse()
        ->and(($this->as)($this->a, fn () => DB::selectOne('SELECT current_user AS role')->role))->toBe(TenantRls::roleName());
});
