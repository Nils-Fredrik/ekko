<?php

use App\Tenancy\Rls\RlsAudit;
use App\Tenancy\TenantSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Every table with a tenant_id column must have complete row-level security. This test fails
// as soon as a migration adds a tenant-owned table without TenantSchema::enableRowLevelSecurity().

test('every tenant-owned table in the migrated schema has row-level security', function () {
    expect(RlsAudit::tablesMissingRls())->toBe([]);
});

test('the RLS role is neither superuser nor allowed to bypass RLS', function () {
    $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?', [config('database.rls.role')]);

    expect($role)->not->toBeNull()
        ->and($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

test('the audit reports a tenant-owned table without row-level security', function () {
    Schema::create('test_unprotected', function (Blueprint $table) {
        $table->id();
        TenantSchema::tenantColumn($table);
    });

    expect(RlsAudit::tablesMissingRls())->toHaveKey('test_unprotected');
});

test('the audit reports RLS that is enabled but not forced', function () {
    Schema::create('test_not_forced', function (Blueprint $table) {
        $table->id();
        TenantSchema::tenantColumn($table);
    });
    TenantSchema::enableRowLevelSecurity('test_not_forced');
    DB::statement('ALTER TABLE test_not_forced NO FORCE ROW LEVEL SECURITY');

    expect(RlsAudit::tablesMissingRls())->toBe(['test_not_forced' => 'RLS not forced']);
});

test('the audit reports a policy with another condition', function () {
    Schema::create('test_wrong_policy', function (Blueprint $table) {
        $table->id();
        TenantSchema::tenantColumn($table);
    });
    DB::statement('ALTER TABLE test_wrong_policy ENABLE ROW LEVEL SECURITY');
    DB::statement('ALTER TABLE test_wrong_policy FORCE ROW LEVEL SECURITY');
    DB::statement('CREATE POLICY tenant_isolation ON test_wrong_policy USING (true)');

    expect(RlsAudit::tablesMissingRls())->toHaveKey('test_wrong_policy');
});

test('the helper gives a table complete row-level security and can remove it again', function () {
    Schema::create('test_protected', function (Blueprint $table) {
        $table->id();
        TenantSchema::tenantColumn($table);
    });

    TenantSchema::enableRowLevelSecurity('test_protected');
    expect(RlsAudit::tablesMissingRls())->not->toHaveKey('test_protected');

    TenantSchema::disableRowLevelSecurity('test_protected');
    expect(RlsAudit::tablesMissingRls())->toHaveKey('test_protected');
});

test('the helper refuses table names that are not plain identifiers', function () {
    TenantSchema::enableRowLevelSecurity('brands; DROP TABLE users');
})->throws(InvalidArgumentException::class);
