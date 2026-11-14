<?php

namespace App\Tenancy\Rls;

use App\Tenancy\TenantSchema;
use Illuminate\Support\Facades\DB;

/**
 * Finds tenant-owned tables (a tenant_id column) in the public schema without complete
 * row-level security: RLS enabled and forced, and the tenant_isolation policy with the expected
 * condition for both USING and WITH CHECK.
 */
final class RlsAudit
{
    /**
     * @return array<string, string> table => what is missing
     */
    public static function tablesMissingRls(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.relname AS table_name,
                   c.relrowsecurity AS enabled,
                   c.relforcerowsecurity AS forced,
                   p.polcmd AS command,
                   pg_get_expr(p.polqual, p.polrelid) AS using_expr,
                   pg_get_expr(p.polwithcheck, p.polrelid) AS check_expr
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
            LEFT JOIN pg_policy p ON p.polrelid = c.oid AND p.polname = ?
            WHERE c.relkind IN ('r', 'p')
            ORDER BY c.relname
            SQL, [TenantSchema::POLICY]);

        $missing = [];
        foreach ($rows as $row) {
            $problems = [];
            if (! $row->enabled) {
                $problems[] = 'RLS not enabled';
            }
            if (! $row->forced) {
                $problems[] = 'RLS not forced';
            }
            if ($row->command === null) {
                $problems[] = 'no '.TenantSchema::POLICY.' policy';
            } else {
                if ($row->command !== '*') {
                    $problems[] = 'policy is not FOR ALL';
                }
                if (! self::isTenantCondition($row->using_expr) || ! self::isTenantCondition($row->check_expr)) {
                    $problems[] = 'policy condition differs from TenantSchema::POLICY_CONDITION';
                }
            }

            if ($problems !== []) {
                $missing[$row->table_name] = implode(', ', $problems);
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
