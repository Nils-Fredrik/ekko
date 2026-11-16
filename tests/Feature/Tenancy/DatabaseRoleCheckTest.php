<?php

use App\Models\User;
use App\Tenancy\AdminConnection;
use App\Tenancy\Rls\DatabaseRoleCheck;
use App\Tenancy\Rls\RlsAudit;
use App\Tenancy\Rls\UnsafeDatabaseRoleException;
use App\Tenancy\TenantSchema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

// T39-F2: a login role that could bypass row-level security stops the instance, and the admin connection has
// one logged way in.

test('the RLS role is a safe login role', function () {
    expect(app(DatabaseRoleCheck::class)->problemsFor(config('database.rls.role')))->toBe([]);
});

test('a superuser login role is reported', function () {
    // Tests log in as the superuser that creates the roles, so the real login role is unsafe here.
    $role = DB::selectOne('SELECT session_user AS role')->role;

    expect(app(DatabaseRoleCheck::class)->problems())->toContain("role {$role} is a superuser");
});

test('a role with BYPASSRLS, or that owns a tenant-owned table, is reported', function () {
    DB::statement('CREATE ROLE ekko_test_bypass NOLOGIN BYPASSRLS');
    DB::statement('CREATE ROLE ekko_test_owner NOLOGIN');
    DB::statement('CREATE ROLE ekko_test_member NOLOGIN IN ROLE ekko_test_owner');
    Schema::create('test_owned', function (Blueprint $table) {
        $table->id();
        $table->foreignId('team_id');
    });
    DB::statement('ALTER TABLE test_owned OWNER TO ekko_test_owner');

    $check = app(DatabaseRoleCheck::class);

    expect($check->problemsFor('ekko_test_bypass'))->toBe(['role ekko_test_bypass has BYPASSRLS'])
        ->and($check->problemsFor('ekko_test_owner'))->toBe(['role ekko_test_owner owns tenant-owned tables: test_owned'])
        // A member of the owner role can act as the owner, so it counts too.
        ->and($check->problemsFor('ekko_test_member'))->toBe(['role ekko_test_member owns tenant-owned tables: test_owned'])
        ->and($check->problemsFor('ekko_test_missing'))->toBe(['role ekko_test_missing does not exist']);
});

test('a role that can switch to a privileged role, or create roles or databases, is reported', function () {
    // Attributes are not inherited, but a member can SET ROLE to the privileged role (ADR 0007).
    DB::statement('CREATE ROLE ekko_test_bypass NOLOGIN BYPASSRLS');
    DB::statement('CREATE ROLE ekko_test_via_bypass NOLOGIN IN ROLE ekko_test_bypass');
    DB::statement('CREATE ROLE ekko_test_creator NOLOGIN CREATEROLE CREATEDB');

    $check = app(DatabaseRoleCheck::class);

    expect($check->problemsFor('ekko_test_via_bypass'))->toBe(['role ekko_test_via_bypass is a member of privileged roles: ekko_test_bypass'])
        ->and($check->problemsFor('ekko_test_creator'))->toBe(['role ekko_test_creator has CREATEROLE', 'role ekko_test_creator has CREATEDB']);
});

test('a member of cloudsqlsuperuser is reported', function () {
    // Cloud SQL makes built-in users members of cloudsqlsuperuser unless told otherwise (ADR 0007).
    if (DB::selectOne("SELECT 1 AS found FROM pg_roles WHERE rolname = 'cloudsqlsuperuser'") === null) {
        DB::statement('CREATE ROLE cloudsqlsuperuser NOLOGIN');
    }
    DB::statement('CREATE ROLE ekko_test_cloudsql NOLOGIN IN ROLE cloudsqlsuperuser');

    expect(app(DatabaseRoleCheck::class)->problemsFor('ekko_test_cloudsql'))
        ->toBe(['role ekko_test_cloudsql is a member of privileged roles: cloudsqlsuperuser']);
});

test('the migrating role owns the tables, so it would be refused as login role', function () {
    $owner = DB::selectOne("SELECT pg_get_userbyid(relowner) AS role FROM pg_class WHERE relname = 'brands' AND relkind = 'r'")->role;

    expect(implode('; ', app(DatabaseRoleCheck::class)->problemsFor($owner)))->toContain('owns tenant-owned tables: ')
        ->toContain('brands');
});

test('with the check on, an unsafe login role fails the health check', function () {
    config(['database.rls.verify_login_role' => true, 'app.debug' => false]);

    $this->get('/up')->assertStatus(500);
});

test('with the check on, a safe login role passes the health check', function () {
    config(['database.rls.verify_login_role' => true]);
    $this->mock(DatabaseRoleCheck::class)->shouldReceive('assertSafe')->once();

    $this->get('/up')->assertOk();
});

// Laravel does not route the console events under unit tests, so the event the console kernel sends before a
// command starts is sent here directly. An exception from it stops the command with a non-zero exit code.
function commandStarting(string $command): CommandStarting
{
    return new CommandStarting($command, new ArrayInput([]), new NullOutput);
}

test('with the check on, the worker and the ekko commands refuse to start on an unsafe login role', function (string $command) {
    config(['database.rls.verify_login_role' => true]);

    expect(fn () => event(commandStarting($command)))->toThrow(UnsafeDatabaseRoleException::class);
})->with(['queue:work', 'queue:listen', 'ekko:dispatch-due', 'ekko:purge-closed-tenants']);

test('with the check on, the migrations and the other commands are not checked', function (string $command) {
    config(['database.rls.verify_login_role' => true]);
    $this->mock(DatabaseRoleCheck::class)->shouldNotReceive('assertSafe');

    event(commandStarting($command));
})->with(['migrate', 'migrate:status', 'config:cache', 'list']);

test('the check is off unless turned on, as locally and in tests', function () {
    expect(DatabaseRoleCheck::enforced())->toBeFalse();

    $this->get('/up')->assertOk();
});

test('every team_id table outside RLS is documented with a reason', function () {
    expect(RlsAudit::undocumentedTeamTables())->toBe([]);
});

test('a team_id table that also has tenant_id is left to the RLS audit', function () {
    Schema::create('test_team_and_tenant', function (Blueprint $table) {
        $table->id();
        $table->foreignId('team_id');
        TenantSchema::tenantColumn($table);
    });

    expect(RlsAudit::undocumentedTeamTables())->toBe([])
        ->and(RlsAudit::tablesMissingRls())->toHaveKey('test_team_and_tenant');
});

test('a new team_id table without a reason is reported', function () {
    Schema::create('test_team_owned', function (Blueprint $table) {
        $table->id();
        $table->foreignId('team_id');
    });

    expect(RlsAudit::undocumentedTeamTables())->toBe([
        'test_team_owned' => 'team_id without RLS and not in RlsAudit::TEAM_TABLES_OUTSIDE_RLS',
    ]);
});

test('the signed-in platform admin can use the admin connection, and every use is logged', function () {
    Log::spy();
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->actingAs($admin);

    $name = app(AdminConnection::class)->run('billing overview', fn (ConnectionInterface $db) => $db->getName());

    expect($name)->toBe('pgsql_admin');
    Log::shouldHaveReceived('notice')->with('admin_connection.used', ['user_id' => $admin->id, 'purpose' => 'billing overview'])->once();
});

test('someone who is not a signed-in platform admin cannot use the admin connection, and the attempt is logged', function (bool $signedIn) {
    Log::spy();
    $user = User::factory()->create();
    if ($signedIn) {
        $this->actingAs($user);
    }
    $called = false;

    expect(fn () => app(AdminConnection::class)->run('look around', function () use (&$called) {
        $called = true;
    }))->toThrow(AuthorizationException::class);

    expect($called)->toBeFalse();
    Log::shouldHaveReceived('warning')->with('admin_connection.denied', ['user_id' => $signedIn ? $user->id : null, 'purpose' => 'look around'])->once();
    Log::shouldNotHaveReceived('notice');
})->with(['signed in, not admin' => true, 'nobody signed in' => false]);
