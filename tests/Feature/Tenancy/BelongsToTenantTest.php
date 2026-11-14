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

test('builder writes cannot change tenant_id', function (string $write) {
    $ownId = insertRecord($this->tenantA, 'own');
    insertRecord($this->tenantB, 'other');
    $b = $this->tenantB->id;

    app(TenantContext::class)->set($this->tenantA->id);

    $query = match ($write) {
        'update' => fn () => TenantRecord::query()->update(['tenant_id' => $b]),
        'update qualified' => fn () => TenantRecord::query()->update(['test_tenant_records.tenant_id' => $b]),
        'increment extra' => fn () => TenantRecord::query()->increment('id', 0, ['tenant_id' => $b]),
        'decrement extra' => fn () => TenantRecord::query()->decrement('id', 0, ['tenant_id' => $b]),
        'incrementEach extra' => fn () => TenantRecord::query()->incrementEach(['id' => 0], ['tenant_id' => $b]),
        'decrementEach extra' => fn () => TenantRecord::query()->decrementEach(['id' => 0], ['tenant_id' => $b]),
        'updateOrInsert values' => fn () => TenantRecord::query()->updateOrInsert(['id' => $ownId], ['tenant_id' => $b]),
        'upsert update columns' => fn () => TenantRecord::query()->upsert(
            [['name' => 'own']], ['tenant_id', 'name'], ['name', 'tenant_id'],
        ),
    };

    expect($query)->toThrow(TenantMismatchException::class);
    expect(DB::table('test_tenant_records')->where('tenant_id', $b)->count())->toBe(1);
})->with([
    'update', 'update qualified', 'increment extra', 'decrement extra', 'incrementEach extra',
    'decrementEach extra', 'updateOrInsert values', 'upsert update columns',
]);

test('builder inserts get tenant_id from the context and refuse another tenant', function (string $write) {
    app(TenantContext::class)->set($this->tenantA->id);
    $now = now();

    $query = match ($write) {
        'insert' => fn () => TenantRecord::query()->insert(['name' => 'x', 'tenant_id' => $this->tenantB->id]),
        'insert many' => fn () => TenantRecord::query()->insert([
            ['name' => 'x', 'tenant_id' => $this->tenantA->id],
            ['name' => 'y', 'tenant_id' => $this->tenantB->id],
        ]),
        'insertOrIgnore' => fn () => TenantRecord::query()->insertOrIgnore(['name' => 'x', 'tenant_id' => $this->tenantB->id]),
        'insertGetId' => fn () => TenantRecord::query()->insertGetId(['name' => 'x', 'tenant_id' => $this->tenantB->id]),
        'insert qualified' => fn () => TenantRecord::query()->insert(['name' => 'x', 'test_tenant_records.tenant_id' => $this->tenantB->id]),
        'upsert' => fn () => TenantRecord::query()->upsert(
            [['name' => 'x', 'tenant_id' => $this->tenantB->id, 'created_at' => $now, 'updated_at' => $now]], ['tenant_id', 'name'], ['name'],
        ),
        'updateOrInsert' => fn () => TenantRecord::query()->updateOrInsert(['name' => 'x', 'tenant_id' => $this->tenantB->id], []),
    };

    expect($query)->toThrow(TenantMismatchException::class);
    expect(DB::table('test_tenant_records')->count())->toBe(0);
})->with(['insert', 'insert many', 'insertOrIgnore', 'insertGetId', 'insert qualified', 'upsert', 'updateOrInsert']);

test('builder inserts without tenant_id are written to the tenant in the context', function () {
    app(TenantContext::class)->set($this->tenantA->id);

    TenantRecord::query()->insert([['name' => 'a'], ['name' => 'b']]);
    TenantRecord::query()->insertOrIgnore(['name' => 'c']);
    TenantRecord::query()->insertGetId(['name' => 'd']);
    TenantRecord::query()->updateOrInsert(['name' => 'e'], []);

    expect(DB::table('test_tenant_records')->distinct()->pluck('tenant_id')->all())->toBe([$this->tenantA->id])
        ->and(DB::table('test_tenant_records')->count())->toBe(5);
});

test('updateOrInsert only finds and updates rows in the tenant in the context', function () {
    $otherId = insertRecord($this->tenantB, 'other');

    app(TenantContext::class)->set($this->tenantA->id);
    TenantRecord::query()->updateOrInsert(['name' => 'other'], ['name' => 'changed']);

    expect(DB::table('test_tenant_records')->where('id', $otherId)->value('name'))->toBe('other')
        ->and(DB::table('test_tenant_records')->where('tenant_id', $this->tenantA->id)->pluck('name')->all())->toBe(['changed']);
});

test('upsert must include tenant_id in the conflict target, so it cannot update another tenant', function () {
    $otherId = insertRecord($this->tenantB, 'other');

    app(TenantContext::class)->set($this->tenantA->id);

    // ON CONFLICT (id) DO UPDATE would hit tenant B's row: the tenant scope does not cover the conflict branch.
    expect(fn () => TenantRecord::query()->upsert([['id' => $otherId, 'name' => 'changed']], ['id'], ['name']))
        ->toThrow(LogicException::class, 'tenant_id in the conflict target');
    expect(fn () => TenantRecord::query()->upsert([['id' => $otherId, 'name' => 'changed']], 'id'))
        ->toThrow(LogicException::class, 'tenant_id in the conflict target');

    expect(DB::table('test_tenant_records')->where('id', $otherId)->value('name'))->toBe('other')
        ->and(DB::table('test_tenant_records')->count())->toBe(1);
});

test('upsert without an update list inserts and updates in the tenant in the context only', function () {
    insertRecord($this->tenantB, 'shared name');

    app(TenantContext::class)->set($this->tenantA->id);

    expect(TenantRecord::query()->upsert([['name' => 'shared name'], ['name' => 'new']], ['tenant_id', 'name']))->toBe(2);
    // Same rows again: conflicts on (tenant_id, name) in tenant A only, so nothing new is inserted.
    TenantRecord::query()->upsert([['name' => 'shared name'], ['name' => 'new']], ['tenant_id', 'name']);

    expect(DB::table('test_tenant_records')->where('tenant_id', $this->tenantA->id)->orderBy('name')->pluck('name')->all())
        ->toBe(['new', 'shared name'])
        ->and(DB::table('test_tenant_records')->where('tenant_id', $this->tenantB->id)->count())->toBe(1);
});

test('incrementEach and decrementEach only touch the tenant in the context', function () {
    DB::statement('ALTER TABLE test_tenant_records ADD COLUMN hits integer NOT NULL DEFAULT 0');
    insertRecord($this->tenantA, 'own');
    insertRecord($this->tenantB, 'other');

    app(TenantContext::class)->set($this->tenantA->id);
    TenantRecord::query()->incrementEach(['hits' => 5]);
    TenantRecord::query()->decrementEach(['hits' => 2]);

    expect(DB::table('test_tenant_records')->orderBy('id')->pluck('hits')->all())->toBe([3, 0]);
});

test('builder writes require a tenant in the context', function (string $write) {
    insertRecord($this->tenantA, 'a');

    $query = match ($write) {
        'update' => fn () => TenantRecord::query()->update(['name' => 'x']),
        'insert' => fn () => TenantRecord::query()->insert(['name' => 'x']),
        'upsert' => fn () => TenantRecord::query()->upsert([['name' => 'x']], ['tenant_id', 'name'], ['name']),
        'updateOrInsert' => fn () => TenantRecord::query()->updateOrInsert(['name' => 'a'], ['name' => 'x']),
        'incrementEach' => fn () => TenantRecord::query()->incrementEach(['id' => 0]),
        'delete' => fn () => TenantRecord::query()->delete(),
    };

    expect($query)->toThrow(MissingTenantContextException::class);
    expect(DB::table('test_tenant_records')->pluck('name')->all())->toBe(['a']);
})->with(['update', 'insert', 'upsert', 'updateOrInsert', 'incrementEach', 'delete']);

test('builder writes the tenant scope cannot cover are refused', function (string $write) {
    app(TenantContext::class)->set($this->tenantA->id);

    $query = match ($write) {
        'updateFrom' => fn () => TenantRecord::query()->updateFrom(['name' => 'x']),
        'insertUsing' => fn () => TenantRecord::query()->insertUsing(['name'], DB::table('teams')->select('name')),
        'insertOrIgnoreUsing' => fn () => TenantRecord::query()->insertOrIgnoreUsing(['name'], DB::table('teams')->select('name')),
        'updateOrInsert callback' => fn () => TenantRecord::query()->updateOrInsert(['name' => 'x'], fn () => ['name' => 'y']),
    };

    expect($query)->toThrow(LogicException::class);
    expect(DB::table('test_tenant_records')->count())->toBe(0);
})->with(['updateFrom', 'insertUsing', 'insertOrIgnoreUsing', 'updateOrInsert callback']);

test('forceDelete only deletes in the tenant in the context', function () {
    $ownId = insertRecord($this->tenantA, 'own');
    $otherId = insertRecord($this->tenantB, 'other');

    app(TenantContext::class)->set($this->tenantA->id);

    expect(TenantRecord::query()->whereKey($otherId)->forceDelete())->toBe(0);
    expect(TenantRecord::query()->forceDelete())->toBe(1);

    expect(DB::table('test_tenant_records')->pluck('id')->all())->toBe([$otherId])
        ->and(DB::table('test_tenant_records')->where('id', $ownId)->exists())->toBeFalse();
});

test('an orWhere cannot escape the tenant in builder writes', function (string $write) {
    DB::statement('ALTER TABLE test_tenant_records ADD COLUMN hits integer NOT NULL DEFAULT 0');
    insertRecord($this->tenantA, 'own');
    $otherId = insertRecord($this->tenantB, 'other');
    DB::table('test_tenant_records')->update(['updated_at' => '2000-01-01 00:00:00']);

    app(TenantContext::class)->set($this->tenantA->id);

    // Without grouping this becomes: id = B OR name = 'own' AND tenant_id = A.
    $query = fn () => TenantRecord::query()->whereKey($otherId)->orWhere('name', 'own');

    match ($write) {
        'update' => $query()->update(['name' => 'changed']),
        'delete' => $query()->delete(),
        'forceDelete' => $query()->forceDelete(),
        'increment' => $query()->increment('hits'),
        'incrementEach' => $query()->incrementEach(['hits' => 1]),
        'touch' => $query()->touch(),
        'updateOrInsert' => TenantRecord::query()->whereKey($otherId)->orWhere('name', 'own')
            ->updateOrInsert(['name' => 'other'], ['hits' => 9]),
    };

    $other = DB::table('test_tenant_records')->where('id', $otherId)->first();
    expect($other)->not->toBeNull()
        ->and($other->name)->toBe('other')
        ->and($other->hits)->toBe(0)
        ->and((string) $other->updated_at)->toStartWith('2000-01-01');
})->with(['update', 'delete', 'forceDelete', 'increment', 'incrementEach', 'touch', 'updateOrInsert']);

test('forceDelete requires a tenant in the context', function () {
    $otherId = insertRecord($this->tenantB, 'other');

    expect(fn () => TenantRecord::query()->whereKey($otherId)->forceDelete())
        ->toThrow(MissingTenantContextException::class);
    expect(DB::table('test_tenant_records')->where('id', $otherId)->exists())->toBeTrue();
});

test('truncate is refused for tenant-owned models', function () {
    insertRecord($this->tenantA, 'a');
    insertRecord($this->tenantB, 'b');

    app(TenantContext::class)->set($this->tenantA->id);

    expect(fn () => TenantRecord::query()->truncate())->toThrow(LogicException::class);
    expect(fn () => TenantRecord::truncate())->toThrow(LogicException::class);
    expect(DB::table('test_tenant_records')->count())->toBe(2);
});

test('touch only touches the tenant in the context and cannot touch tenant_id', function () {
    insertRecord($this->tenantA, 'own');
    $otherId = insertRecord($this->tenantB, 'other');
    DB::table('test_tenant_records')->update(['updated_at' => '2000-01-01 00:00:00']);

    app(TenantContext::class)->set($this->tenantA->id);

    expect(fn () => TenantRecord::query()->touch('tenant_id'))->toThrow(TenantMismatchException::class);
    expect(TenantRecord::query()->touch())->toBe(1);

    expect((string) DB::table('test_tenant_records')->where('id', $otherId)->value('updated_at'))->toStartWith('2000-01-01');
});

test('fillAndInsert writes to the tenant in the context and refuses another tenant', function () {
    app(TenantContext::class)->set($this->tenantA->id);

    TenantRecord::query()->fillAndInsert([['name' => 'a']]);
    TenantRecord::query()->fillAndInsertGetId(['name' => 'b']);

    expect(fn () => TenantRecord::query()->fillAndInsert([['name' => 'c', 'tenant_id' => $this->tenantB->id]]))
        ->toThrow(TenantMismatchException::class);
    expect(DB::table('test_tenant_records')->distinct()->pluck('tenant_id')->all())->toBe([$this->tenantA->id])
        ->and(DB::table('test_tenant_records')->count())->toBe(2);
});

test('a tenant-owned row is deleted with its tenant', function () {
    insertRecord($this->tenantA, 'a');

    $this->tenantA->delete();

    expect(DB::table('test_tenant_records')->count())->toBe(0);
});
