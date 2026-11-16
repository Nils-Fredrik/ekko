<?php

namespace App\Tenancy\Rls;

use Illuminate\Support\Facades\DB;

/**
 * Checks the role the application logs in with (T39-F2). Code outside TenantRls::run() runs as that
 * role, so with a wrong DB_USERNAME row-level security would stop nothing and nobody would notice.
 * A safe login role is no superuser, has no BYPASSRLS, and neither owns nor is a member of the owner
 * of a tenant-owned table (an owner can turn RLS off). In production the check runs when an instance
 * starts (the /up startup probe) and before the worker and the ekko:* commands (AppServiceProvider).
 */
class DatabaseRoleCheck
{
    public static function enforced(): bool
    {
        return (bool) config('database.rls.verify_login_role');
    }

    /**
     * @return list<string> what is unsafe about the login role, empty when it is safe
     */
    public function problems(): array
    {
        return $this->problemsFor((string) DB::selectOne('SELECT session_user AS role')->role);
    }

    /**
     * @return list<string>
     */
    public function problemsFor(string $role): array
    {
        $flags = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?', [$role]);
        if ($flags === null) {
            return ["role {$role} does not exist"];
        }

        $problems = [];
        if ($flags->rolsuper) {
            $problems[] = "role {$role} is a superuser";
        }
        if ($flags->rolbypassrls) {
            $problems[] = "role {$role} has BYPASSRLS";
        }

        $owned = DB::select(<<<'SQL'
            SELECT DISTINCT c.relname AS name
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname IN ('tenant_id', 'team_id') AND NOT a.attisdropped
            WHERE c.relkind IN ('r', 'p', 'v', 'm') AND pg_has_role(?, c.relowner, 'MEMBER')
            ORDER BY c.relname
            SQL, [$role]);
        if ($owned !== []) {
            $problems[] = "role {$role} owns tenant-owned tables: ".implode(', ', array_column($owned, 'name'));
        }

        return $problems;
    }

    /**
     * @throws UnsafeDatabaseRoleException
     */
    public function assertSafe(): void
    {
        $problems = $this->problems();

        if ($problems !== []) {
            throw new UnsafeDatabaseRoleException('Unsafe database login role, row-level security would not apply: '.implode('; ', $problems));
        }
    }
}
