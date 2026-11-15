<?php

use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delivery and acknowledgement of alerts (docs/spec.md 11.1, ADR 0019).
        Schema::table('alerts', function (Blueprint $table): void {
            $table->string('subject')->nullable()->after('alert_rule_id');
            $table->date('digest_date')->nullable()->after('payload');
            $table->jsonb('deliveries')->default('{}')->after('digest_date');
            $table->timestampTz('acknowledged_at')->nullable()->after('acknowledged_by');
            $table->index(['tenant_id', 'alert_rule_id', 'subject'], 'alerts_subject_index');
            $table->index(['tenant_id', 'digest_date']);
        });

        // One row per tenant and local day that the alert evaluation has run for. The unique key is what
        // makes the evaluation run once a day however often the scheduler starts it.
        Schema::create('alert_evaluations', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->date('local_date');
            $table->timestampTz('evaluated_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'local_date']);
        });
        TenantSchema::enableRowLevelSecurity('alert_evaluations');
    }

    public function down(): void
    {
        TenantSchema::disableRowLevelSecurity('alert_evaluations');
        Schema::dropIfExists('alert_evaluations');

        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropIndex('alerts_subject_index');
            $table->dropIndex(['tenant_id', 'digest_date']);
            $table->dropColumn(['subject', 'digest_date', 'deliveries', 'acknowledged_at']);
        });
    }
};
