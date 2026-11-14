<?php

use App\Tenancy\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The application role for row-level security (docs/spec.md 5.3).
     *
     * In production the role is a Cloud SQL login user created by Terraform (T09), so it already
     * exists here. Locally and in CI it is created as a NOLOGIN role that the superuser switches to.
     * Roles are cluster-wide, so the role is never dropped in down().
     */
    public function up(): void
    {
        $role = TenantRls::roleName();

        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN
                    CREATE ROLE "{$role}" NOLOGIN NOSUPERUSER NOBYPASSRLS;
                END IF;
            END
            \$\$
            SQL);

        $attributes = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?', [$role]);
        if ($attributes->rolsuper || $attributes->rolbypassrls) {
            throw new RuntimeException("The RLS role {$role} must not be a superuser or have BYPASSRLS.");
        }

        DB::statement("GRANT USAGE ON SCHEMA public TO \"{$role}\"");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO \"{$role}\"");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO \"{$role}\"");

        // Tables and sequences that later migrations create (as the migrating user) get the same grants.
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO \"{$role}\"");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO \"{$role}\"");
    }

    public function down(): void
    {
        $role = TenantRls::roleName();

        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM \"{$role}\"");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM \"{$role}\"");
        DB::statement("REVOKE SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public FROM \"{$role}\"");
        DB::statement("REVOKE USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public FROM \"{$role}\"");
        DB::statement("REVOKE USAGE ON SCHEMA public FROM \"{$role}\"");
    }
};
