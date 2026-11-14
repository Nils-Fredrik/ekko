<?php

use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ReadTenantRecordsJob;
use Tests\Fixtures\TenantRecord;

// docs/spec.md 5.3, layer 2: row-level security stops cross-tenant access even when layer 1
// (the Eloquent tenant scope) is removed or bypassed with raw queries.

beforeEach(function () {
    TenantRecord::createTable();
    $this->tenantA = Team::factory()->tenant()->create();
    $this->tenantB = Team::factory()->tenant()->create();

    // Inserted as the migrating (super)user, outside row-level security.
    foreach ([[$this->tenantA, 'a1'], [$this->tenantA, 'a2'], [$this->tenantB, 'b1']] as [$tenant, $name]) {
        DB::table('test_tenant_records')->insert([
            'tenant_id' => $tenant->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->rls = app(TenantRls::class);
});

test('RLS stops cross-tenant reads when the global scope is removed', function () {
    $context = app(TenantContext::class);
    $context->set($this->tenantA->id);

    $seen = $this->rls->run([$this->tenantA->id], fn () => [
        'unscoped' => TenantRecord::withoutGlobalScope(TenantScope::class)->orderBy('name')->pluck('name')->all(),
        'without_all_scopes' => TenantRecord::withoutGlobalScopes()->count(),
        'raw' => DB::table('test_tenant_records')->orderBy('name')->pluck('name')->all(),
        'raw_other' => DB::table('test_tenant_records')->where('tenant_id', $this->tenantB->id)->count(),
        'sql' => DB::selectOne('SELECT count(*) AS n FROM test_tenant_records')->n,
    ]);

    expect($seen)->toBe([
        'unscoped' => ['a1', 'a2'],
        'without_all_scopes' => 2,
        'raw' => ['a1', 'a2'],
        'raw_other' => 0,
        'sql' => 2,
    ]);
});

test('without tenants no tenant-owned rows are visible', function () {
    expect($this->rls->run([], fn () => DB::table('test_tenant_records')->count()))->toBe(0);
});

test('a partner with several tenants sees exactly those tenants', function () {
    $tenantC = Team::factory()->tenant()->create();
    DB::table('test_tenant_records')->insert(['tenant_id' => $tenantC->id, 'name' => 'c1', 'created_at' => now(), 'updated_at' => now()]);

    $names = $this->rls->run(
        [$this->tenantA->id, $this->tenantB->id],
        fn () => DB::table('test_tenant_records')->orderBy('name')->pluck('name')->all(),
    );

    expect($names)->toBe(['a1', 'a2', 'b1']);
});

test('RLS refuses inserts into another tenant', function () {
    expect(fn () => $this->rls->run([$this->tenantA->id], fn () => DB::table('test_tenant_records')->insert([
        'tenant_id' => $this->tenantB->id, 'name' => 'sneaky', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'row-level security');

    expect(DB::table('test_tenant_records')->where('name', 'sneaky')->exists())->toBeFalse();
});

test('RLS refuses moving a row to another tenant', function () {
    expect(fn () => $this->rls->run([$this->tenantA->id], fn () => DB::table('test_tenant_records')
        ->where('name', 'a1')->update(['tenant_id' => $this->tenantB->id])))
        ->toThrow(QueryException::class, 'row-level security');

    expect(DB::table('test_tenant_records')->where('name', 'a1')->value('tenant_id'))->toBe($this->tenantA->id);
});

test('RLS hides other tenants from raw updates and deletes', function () {
    $affected = $this->rls->run([$this->tenantA->id], fn () => [
        'update' => DB::table('test_tenant_records')->where('name', 'b1')->update(['name' => 'changed']),
        'delete' => DB::table('test_tenant_records')->where('name', 'b1')->delete(),
    ]);

    expect($affected)->toBe(['update' => 0, 'delete' => 0])
        ->and(DB::table('test_tenant_records')->where('name', 'b1')->exists())->toBeTrue();
});

test('the transaction runs as the RLS role and restores the previous role and tenants', function () {
    $before = $this->rls->currentSettings();

    $inside = $this->rls->run([$this->tenantA->id], fn () => $this->rls->currentSettings());

    expect($inside)->toBe(['role' => 'ekko_app', 'tenant_ids' => (string) $this->tenantA->id])
        ->and($this->rls->currentSettings())->toBe($before);
});

test('nested runs restore the outer tenants', function () {
    $result = $this->rls->run([$this->tenantA->id], function () {
        $inner = $this->rls->run([$this->tenantB->id], fn () => DB::table('test_tenant_records')->pluck('name')->all());

        return [$inner, DB::table('test_tenant_records')->orderBy('name')->pluck('name')->all()];
    });

    expect($result)->toBe([['b1'], ['a1', 'a2']]);
});

test('a failing callback rolls back its writes and restores the settings', function () {
    $before = $this->rls->currentSettings();

    expect(fn () => $this->rls->run([$this->tenantA->id], function () {
        DB::table('test_tenant_records')->insert(['tenant_id' => $this->tenantA->id, 'name' => 'a3', 'created_at' => now(), 'updated_at' => now()]);
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(DB::table('test_tenant_records')->where('name', 'a3')->exists())->toBeFalse()
        ->and($this->rls->currentSettings())->toBe($before);
});

test('commitIf can roll back a successful callback', function () {
    $this->rls->run([$this->tenantA->id], function () {
        DB::table('test_tenant_records')->insert(['tenant_id' => $this->tenantA->id, 'name' => 'a3', 'created_at' => now(), 'updated_at' => now()]);

        return 'failed';
    }, fn (string $result) => $result !== 'failed');

    expect(DB::table('test_tenant_records')->where('name', 'a3')->exists())->toBeFalse();
});

test('tenant ids must be positive integers', function (mixed $id) {
    $this->rls->run([$id], fn () => null);
})->with([0, -1, '1', '1,2', null])->throws(InvalidArgumentException::class);

test('the RLS role must be a plain identifier', function () {
    config(['database.rls.role' => 'ekko_app"; DROP TABLE users; --']);

    TenantRls::roleName();
})->throws(InvalidArgumentException::class);

test('at the top level the settings end with the transaction', function () {
    // A separate connection outside the RefreshDatabase transaction, like a request in production.
    config(['database.connections.pgsql_probe' => config('database.connections.pgsql')]);

    $this->rls->run([$this->tenantA->id], fn () => null, connection: 'pgsql_probe');

    $after = DB::connection('pgsql_probe')
        ->selectOne("SELECT current_user AS role, coalesce(current_setting('app.tenant_ids', true), '') AS ids");

    expect($after->role)->not->toBe('ekko_app')
        ->and($after->ids)->toBe('');

    DB::purge('pgsql_probe');
});

test('a tenant job only sees its own tenant, even without the global scope', function () {
    dispatch_sync(new ReadTenantRecordsJob($this->tenantB->id));

    expect(Cache::get('rls-job-result'))->toBe([
        'eloquent_unscoped' => [$this->tenantB->id],
        'raw' => [$this->tenantB->id],
    ]);
});

test('a tenant job cannot write raw rows into another tenant', function () {
    expect(fn () => dispatch_sync(new ReadTenantRecordsJob($this->tenantA->id, rawInsertTenantId: $this->tenantB->id)))
        ->toThrow(QueryException::class, 'row-level security');

    expect(DB::table('test_tenant_records')->where('name', 'raw')->exists())->toBeFalse();
});
