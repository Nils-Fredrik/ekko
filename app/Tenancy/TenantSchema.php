<?php

namespace App\Tenancy;

use Illuminate\Database\Schema\Blueprint;

/**
 * Schema helpers for tenant-owned tables (docs/spec.md 6: tenant_id bigint not null,
 * foreign key to teams.id and an index). T04 adds row-level security on top.
 */
final class TenantSchema
{
    public static function tenantColumn(Blueprint $table): void
    {
        $table->foreignId('tenant_id')->constrained('teams')->cascadeOnDelete();
        $table->index('tenant_id');
    }
}
