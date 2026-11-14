<?php

namespace App\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Schema helpers for tenant-owned tables (docs/spec.md 5.3 and 6).
 *
 * Every tenant-owned table needs both:
 *
 *     Schema::create('brands', function (Blueprint $table) {
 *         $table->id();
 *         TenantSchema::tenantColumn($table);
 *         …
 *     });
 *     TenantSchema::enableRowLevelSecurity('brands');
 *
 * and in down(): TenantSchema::disableRowLevelSecurity('brands') before dropping the table.
 * tests/Feature/Tenancy/RlsCoverageTest fails for any table with tenant_id but without RLS.
 */
final class TenantSchema
{
    public const POLICY = 'tenant_isolation';

    /**
     * The tenant condition shared by USING and WITH CHECK: rows are visible and writable only for
     * the tenants in app.tenant_ids. Unset or empty means no rows.
     */
    public const POLICY_CONDITION = "tenant_id = ANY (string_to_array(current_setting('app.tenant_ids', true), ',')::bigint[])";

    /**
     * tenant_id bigint not null, foreign key to teams.id and an index.
     */
    public static function tenantColumn(Blueprint $table): void
    {
        $table->foreignId('tenant_id')->constrained('teams')->cascadeOnDelete();
        $table->index('tenant_id');
    }

    /**
     * Enables and forces row-level security with the tenant_isolation policy. FORCE makes the
     * policy apply to the table owner as well; only superusers and BYPASSRLS roles skip it.
     */
    public static function enableRowLevelSecurity(string $table): void
    {
        $quoted = self::quote($table);

        DB::statement("ALTER TABLE {$quoted} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$quoted} FORCE ROW LEVEL SECURITY");
        DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$quoted}");
        DB::statement('CREATE POLICY '.self::POLICY." ON {$quoted} AS PERMISSIVE FOR ALL TO PUBLIC"
            .' USING ('.self::POLICY_CONDITION.')'
            .' WITH CHECK ('.self::POLICY_CONDITION.')');
    }

    public static function disableRowLevelSecurity(string $table): void
    {
        $quoted = self::quote($table);

        DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$quoted}");
        DB::statement("ALTER TABLE {$quoted} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$quoted} DISABLE ROW LEVEL SECURITY");
    }

    private static function quote(string $table): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }

        return '"'.$table.'"';
    }
}
