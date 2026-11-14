<?php

use App\Models\Team;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMismatchException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TenantRecord;

beforeEach(function () {
    TenantRecord::createTable();
    $this->tenantA = Team::factory()->tenant()->create();
    $this->tenantB = Team::factory()->tenant()->create();
});

function insertRecord(Team $tenant, string $name): int
{
    return DB::table('test_tenant_records')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('a model with the trait cannot be created without a tenant', function () {
    expect(fn () => TenantRecord::create(['name' => 'x']))->toThrow(MissingTenantContextException::class);
    expect(fn () => TenantRecord::create(['name' => 'x', 'tenant_id' => $this->tenantA->id]))
        ->toThrow(MissingTenantContextException::class);

    expect(DB::table('test_tenant_records')->count())->toBe(0);
});

test('a model with the trait cannot be read without a tenant', function (string $read) {
    insertRecord($this->tenantA, 'a');

    $query = match ($read) {
        'get' => fn () => TenantRecord::all(),
        'find' => fn () => TenantRecord::find(1),
        'first' => fn () => TenantRecord::query()->first(),
        'count' => fn () => TenantRecord::count(),
        'exists' => fn () => TenantRecord::query()->exists(),
        'pluck' => fn () => TenantRecord::query()->pluck('name'),
    };

    expect($query)->toThrow(MissingTenantContextException::class);
})->with(['get', 'find', 'first', 'count', 'exists', 'pluck']);

test('tenant_id is set from the context on create', function () {
    app(TenantContext::class)->set($this->tenantA->id);

    $record = TenantRecord::create(['name' => 'a']);

    expect($record->tenant_id)->toBe($this->tenantA->id)
        ->and(DB::table('test_tenant_records')->where('id', $record->id)->value('tenant_id'))->toBe($this->tenantA->id);
});

test('queries only return the tenant in the context', function () {
    $ownId = insertRecord($this->tenantA, 'own');
    $otherId = insertRecord($this->tenantB, 'other');

    app(TenantContext::class)->set($this->tenantA->id);

    expect(TenantRecord::pluck('name')->all())->toBe(['own'])
        ->and(TenantRecord::find($otherId))->toBeNull()
        ->and(TenantRecord::find($ownId))->not->toBeNull()
        ->and(TenantRecord::count())->toBe(1);
});

test('the tenant relation points to the team', function () {
    app(TenantContext::class)->set($this->tenantA->id);

    expect(TenantRecord::create(['name' => 'a'])->tenant->is($this->tenantA))->toBeTrue();
});

test('a model cannot be created in another tenant than the context', function () {
    app(TenantContext::class)->set($this->tenantA->id);

    expect(fn () => TenantRecord::create(['name' => 'x', 'tenant_id' => $this->tenantB->id]))
        ->toThrow(TenantMismatchException::class);
    expect(DB::table('test_tenant_records')->count())->toBe(0);
});

test('tenant_id cannot be changed', function () {
    app(TenantContext::class)->set($this->tenantA->id);
    $record = TenantRecord::create(['name' => 'a']);

    $record->tenant_id = $this->tenantB->id;

    expect(fn () => $record->save())->toThrow(TenantMismatchException::class);
    expect(DB::table('test_tenant_records')->where('id', $record->id)->value('tenant_id'))->toBe($this->tenantA->id);
});

test('a model loaded in one tenant cannot be updated or deleted while the context is another tenant', function () {
    app(TenantContext::class)->set($this->tenantA->id);
    $record = TenantRecord::create(['name' => 'a']);

    app(TenantContext::class)->set($this->tenantB->id);

    $record->name = 'changed';
    expect(fn () => $record->save())->toThrow(TenantMismatchException::class);
    expect(fn () => $record->delete())->toThrow(TenantMismatchException::class);

    expect(DB::table('test_tenant_records')->where('id', $record->id)->value('name'))->toBe('a');
});

test('a model cannot be updated or deleted without a tenant', function () {
    app(TenantContext::class)->set($this->tenantA->id);
    $record = TenantRecord::create(['name' => 'a']);
    app(TenantContext::class)->forget();

    $record->name = 'changed';
    expect(fn () => $record->save())->toThrow(MissingTenantContextException::class);
    expect(fn () => $record->delete())->toThrow(MissingTenantContextException::class);
});

test('mass updates and deletes only touch the tenant in the context', function () {
    insertRecord($this->tenantA, 'own');
    insertRecord($this->tenantB, 'other');

    app(TenantContext::class)->set($this->tenantA->id);

    expect(TenantRecord::query()->update(['name' => 'renamed']))->toBe(1);
    expect(TenantRecord::query()->delete())->toBe(1);

    expect(DB::table('test_tenant_records')->pluck('name')->all())->toBe(['other']);
});

test('a tenant-owned row is deleted with its tenant', function () {
    insertRecord($this->tenantA, 'a');

    $this->tenantA->delete();

    expect(DB::table('test_tenant_records')->count())->toBe(0);
});
