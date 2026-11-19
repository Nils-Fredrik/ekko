<?php

use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable, event-driven processing work (docs/spec.md 7.1, ADR 0032, T40-F2). One row per tenant, kind and scope:
 * a signal raises version in the same transaction as the data change, and a processor acknowledges only the
 * version it leased, so signals that arrive while it runs stay pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_signals', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->string('kind', 32);
            // Internal identifiers only (a run batch id, a local date, a month), never free text: see the check below.
            $table->string('scope', 40);
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedBigInteger('processed_version')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('pending_since')->nullable();
            $table->timestampTz('last_signaled_at');
            $table->uuid('lease_token')->nullable();
            $table->unsignedBigInteger('lease_version')->nullable();
            $table->timestampTz('leased_at')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            // The exception class of the last failure, never its message.
            $table->string('last_error', 120)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'kind', 'scope']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE work_signals
                ADD CONSTRAINT work_signals_versions CHECK (processed_version >= 0 AND version >= processed_version),
                ADD CONSTRAINT work_signals_lease CHECK (
                    (lease_token IS NULL AND lease_version IS NULL AND lease_expires_at IS NULL AND leased_at IS NULL)
                    OR (lease_token IS NOT NULL AND lease_version IS NOT NULL AND lease_expires_at IS NOT NULL AND leased_at IS NOT NULL
                        AND lease_version > processed_version AND lease_version <= version)
                ),
                ADD CONSTRAINT work_signals_kind CHECK (kind ~ '^[a-z][a-z_]{0,31}$'),
                ADD CONSTRAINT work_signals_scope CHECK (
                    scope = 'tenant'
                    OR scope ~ '^[a-z][a-z_]{0,23}:[1-9][0-9]{0,14}$'
                    OR scope ~ '^[0-9]{4}-[0-9]{2}(-[0-9]{2})?$'
                )
            SQL);

        // The recovery sweep and the lag measurement read only pending rows.
        DB::statement('CREATE INDEX work_signals_pending ON work_signals (tenant_id, available_at) WHERE version > processed_version');

        TenantSchema::enableRowLevelSecurity('work_signals');
    }

    public function down(): void
    {
        TenantSchema::disableRowLevelSecurity('work_signals');
        Schema::dropIfExists('work_signals');
    }
};
