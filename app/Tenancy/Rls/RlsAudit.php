<?php

namespace App\Tenancy\Rls;

use App\Tenancy\TenantSchema;
use Illuminate\Support\Facades\DB;

/**
 * Finds tenant-owned tables (a tenant_id column) in the public schema without complete
 * row-level security: RLS enabled and forced, the permissive tenant_isolation policy for PUBLIC
 * with the expected condition for both USING and WITH CHECK, and no other permissive policy
 * (permissive policies are OR-ed, so an extra one would widen access). Restrictive policies
 * only narrow access and are allowed.
 */
final class RlsAudit
{
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
