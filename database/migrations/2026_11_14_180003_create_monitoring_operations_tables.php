<?php

use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->timestampTz('occurred_at');
            $table->enum('kind', ['engine_call', 'extraction', 'onboarding', 'pdf']);
            $table->string('engine_key')->nullable();
            $table->unsignedInteger('calls')->default(1);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('search_calls')->default(0);
            $table->decimal('cost_usd', 16, 8)->default(0);
            $table->enum('billed_to', ['platform', 'byok'])->default('platform');
            $table->unsignedBigInteger('run_id')->nullable();
            $table->timestampsTz();
            $table->foreign('engine_key')->references('key')->on('engines');
            $table->index('run_id');
            $table->index(['tenant_id', 'occurred_at']);
        });
        TenantSchema::enableRowLevelSecurity('usage_events');

        DB::statement('ALTER TABLE usage_events ADD FOREIGN KEY (tenant_id, run_id) REFERENCES runs (tenant_id, id) ON DELETE SET NULL (run_id)');

        Schema::create('usage_monthly', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->date('month');
            $table->unsignedBigInteger('calls')->default(0);
            $table->decimal('cost_usd', 16, 8)->default(0);
            $table->unsignedBigInteger('quota_calls')->nullable();
            $table->decimal('budget_usd', 12, 2)->nullable();
            $table->enum('status', ['ok', 'warning', 'capped'])->default('ok');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'month']);
        });
        TenantSchema::enableRowLevelSecurity('usage_monthly');

        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->enum('type', ['visibility_drop', 'competitor_takeover', 'citation_lost', 'new_competitor', 'model_change', 'quota', 'integration_error']);
            $table->decimal('threshold', 8, 4)->nullable();
            $table->jsonb('channels')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index('brand_id');
        });
        TenantSchema::enableRowLevelSecurity('alert_rules');

        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('alert_rule_id');
            $table->timestampTz('triggered_at');
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('delivered_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'alert_rule_id'])->references(['tenant_id', 'id'])->on('alert_rules')->cascadeOnDelete();
            $table->index('alert_rule_id');
            $table->index(['tenant_id', 'triggered_at']);
        });
        TenantSchema::enableRowLevelSecurity('alerts');

        Schema::create('report_schedules', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->enum('cadence', ['weekly', 'monthly']);
            $table->jsonb('recipients')->default('[]');
            $table->string('locale', 10);
            $table->enum('branding', ['tenant', 'partner'])->default('tenant');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index('brand_id');
        });
        TenantSchema::enableRowLevelSecurity('report_schedules');

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('report_schedule_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->text('pdf_uri')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'report_schedule_id'])->references(['tenant_id', 'id'])->on('report_schedules')->cascadeOnDelete();
            $table->unique(['tenant_id', 'report_schedule_id', 'period_start', 'period_end'], 'reports_schedule_period_unique');
        });
        TenantSchema::enableRowLevelSecurity('reports');

        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->enum('type', ['clickhouse_logs', 'ga4']);
            $table->jsonb('config');
            $table->timestampTz('last_sync_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'brand_id', 'type']);
        });
        TenantSchema::enableRowLevelSecurity('integrations');

        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'activity_log_subject_index');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'activity_log_causer_index');
            $table->jsonb('attribute_changes')->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'created_at']);
        });
        TenantSchema::enableRowLevelSecurity('activity_log');
    }

    public function down(): void
    {
        TenantSchema::disableRowLevelSecurity('activity_log');
        Schema::dropIfExists('activity_log');
        TenantSchema::disableRowLevelSecurity('integrations');
        Schema::dropIfExists('integrations');
        TenantSchema::disableRowLevelSecurity('reports');
        Schema::dropIfExists('reports');
        TenantSchema::disableRowLevelSecurity('report_schedules');
        Schema::dropIfExists('report_schedules');
        TenantSchema::disableRowLevelSecurity('alerts');
        Schema::dropIfExists('alerts');
        TenantSchema::disableRowLevelSecurity('alert_rules');
        Schema::dropIfExists('alert_rules');
        TenantSchema::disableRowLevelSecurity('usage_monthly');
        Schema::dropIfExists('usage_monthly');
        TenantSchema::disableRowLevelSecurity('usage_events');
        Schema::dropIfExists('usage_events');
    }
};
