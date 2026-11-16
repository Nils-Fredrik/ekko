<?php

namespace App\Tenancy\Rls;

use App\Tenancy\TenantSchema;
use Illuminate\Support\Facades\DB;

/**
 * Finds tenant-owned tables (a tenant_id column) in the public schema without complete
 * row-level security: RLS enabled and forced, the permissive tenant_isolation policy for PUBLIC
 * with the expected condition for both USING and WITH CHECK, and no other permissive policy
 * (permissive policies are OR-ed, so an extra one would widen access). Restrictive policies
 * only narrow access and are allowed. Views with tenant_id must use security_invoker, and
 * materialized views with tenant_id are not allowed.
 */
final class RlsAudit
{
    /**
     * Tables that belong to a tenant through team_id and are deliberately outside RLS (T39-F2). They are
     * read to find out which tenants someone may use, before there is a tenant context to filter on, so
     * the application filters them itself. A new table with team_id must be added here with a reason, or
     * also get tenant_id, which tablesMissingRls() then holds to complete RLS
     * (tests/Feature/Tenancy/DatabaseRoleCheckTest.php).
     *
     * @var array<string, string>
     */
    public const TEAM_TABLES_OUTSIDE_RLS = [
        'team_user' => 'memberships decide which tenants a user may see (TenantAccess), so they are read before the tenant context is set',
        'team_invitations' => 'read by the invited person, who is not yet a member, when the invitation is accepted',
        'mcp_connections' => 'resolved from the access token on /mcp before the tenant context is set (AuthenticateMcp)',
        'mcp_auth_codes' => 'exchanged for a token on /oauth/token, where there is no user or tenant context',
    ];

    /**
     * Tables and views with a team_id column and no tenant_id column that are not in TEAM_TABLES_OUTSIDE_RLS,
     * and entries in it for tables that no longer have the column. Views are included on purpose: a view
     * with team_id exposes the same rows. One with tenant_id is left to tablesMissingRls().
     *
     * @return array<string, string> table => what is wrong
     */
    public static function undocumentedTeamTables(): array
    {
        $tables = array_column(DB::select(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'team_id' AND NOT a.attisdropped
            WHERE c.relkind IN ('r', 'p', 'v', 'm')
              AND NOT EXISTS (SELECT 1 FROM pg_attribute t WHERE t.attrelid = c.oid AND t.attname = 'tenant_id' AND NOT t.attisdropped)
            ORDER BY c.relname
            SQL), 'name');

        $problems = [];
        foreach (array_diff($tables, array_keys(self::TEAM_TABLES_OUTSIDE_RLS)) as $table) {
            $problems[$table] = 'team_id without RLS and not in RlsAudit::TEAM_TABLES_OUTSIDE_RLS';
        }
        foreach (array_diff(array_keys(self::TEAM_TABLES_OUTSIDE_RLS), $tables) as $table) {
            $problems[$table] = 'listed in RlsAudit::TEAM_TABLES_OUTSIDE_RLS but has no team_id column';
        }

        return $problems;
    }

    /**
     * @return array<string, string> table => what is missing
     */
    public static function tablesMissingRls(): array
    {
        $tables = DB::select(<<<'SQL'
            SELECT c.oid, c.relname AS table_name, c.relrowsecurity AS enabled, c.relforcerowsecurity AS forced
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
            WHERE c.relkind IN ('r', 'p')
            ORDER BY c.relname
            SQL);

        $missing = [];
        foreach ($tables as $table) {
            $policies = DB::select(<<<'SQL'
                SELECT polname AS name,
                       polcmd AS command,
                       polpermissive AS permissive,
                       polroles = '{0}'::oid[] AS for_public,
                       pg_get_expr(polqual, polrelid) AS using_expr,
                       pg_get_expr(polwithcheck, polrelid) AS check_expr
                FROM pg_policy
                WHERE polrelid = ?
                ORDER BY polname
                SQL, [$table->oid]);

            $problems = [];
            if (! $table->enabled) {
                $problems[] = 'RLS not enabled';
            }
            if (! $table->forced) {
                $problems[] = 'RLS not forced';
            }

            $isolation = null;
            foreach ($policies as $policy) {
                if ($policy->name === TenantSchema::POLICY) {
                    $isolation = $policy;
                } elseif ($policy->permissive) {
                    // Permissive policies are OR-ed with tenant_isolation, so any other one widens access.
                    $problems[] = "extra permissive policy {$policy->name}";
                }
            }

            if ($isolation === null) {
                $problems[] = 'no '.TenantSchema::POLICY.' policy';
            } else {
                if ($isolation->command !== '*') {
                    $problems[] = 'policy is not FOR ALL';
                }
                if (! $isolation->permissive) {
                    $problems[] = 'policy is not permissive';
                }
                if (! $isolation->for_public) {
                    $problems[] = 'policy does not apply to PUBLIC';
                }
                if (! self::isTenantCondition($isolation->using_expr) || ! self::isTenantCondition($isolation->check_expr)) {
                    $problems[] = 'policy condition differs from TenantSchema::POLICY_CONDITION';
                }
            }

            if ($problems !== []) {
                $missing[$table->table_name] = implode(', ', $problems);
            }
        }

        return $missing + self::viewsBypassingRls();
    }

    /**
     * Views and materialized views with a tenant_id column. A plain view runs with its owner's
     * rights, so a view owned by the migrating (super)user bypasses RLS unless it is created
     * WITH (security_invoker = true). A materialized view has no RLS at all.
     *
     * @return array<string, string>
     */
    private static function viewsBypassingRls(): array
    {
        $views = DB::select(<<<'SQL'
            SELECT c.relname AS name, c.relkind AS kind, coalesce(c.reloptions, '{}') AS options
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
            WHERE c.relkind IN ('v', 'm')
            ORDER BY c.relname
            SQL);

        $missing = [];
        foreach ($views as $view) {
            if ($view->kind === 'm') {
                $missing[$view->name] = 'materialized view with tenant_id (no RLS)';

                continue;
            }

            $options = array_map('strtolower', str_getcsv(trim((string) $view->options, '{}'), ',', '"', '\\'));
            $invoker = array_intersect($options, ['security_invoker=true', 'security_invoker=on', 'security_invoker=1', 'security_invoker=yes']);

            if ($invoker === []) {
                $missing[$view->name] = 'view with tenant_id without security_invoker';
            }
        }

        return $missing;
    }

    /**
     * PostgreSQL stores the condition as
     *   (tenant_id = ANY ((string_to_array(current_setting('app.tenant_ids'::text, true), ','::text))::bigint[]))
     * Compared without whitespace and parentheses, it must match exactly, so a weakened policy
     * such as (true OR tenant_id = …) is reported.
     */
    private static function isTenantCondition(?string $expression): bool
    {
        if ($expression === null) {
            return false;
        }

        return self::normalize($expression)
            === "tenant_id=anystring_to_arraycurrent_setting'app.tenant_ids'::text,true,','::text::bigint[]";
    }

    private static function normalize(string $expression): string
    {
        return strtolower((string) preg_replace('/\s+|[()]/', '', $expression));
    }
}
