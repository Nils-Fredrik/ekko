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
        Schema::table('plans', function (Blueprint $table): void {
            $table->jsonb('engine_max_frequencies')->default('{}');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->unsignedBigInteger('monthly_call_quota_override')->nullable();
            $table->decimal('monthly_byok_budget_usd', 12, 2)->nullable();
        });
        DB::statement('ALTER TABLE teams ADD CHECK (monthly_call_quota_override >= 0), ADD CHECK (monthly_byok_budget_usd >= 0)');

        Schema::table('usage_monthly', function (Blueprint $table): void {
            $table->decimal('byok_cost_usd', 16, 8)->default(0);
            $table->decimal('byok_budget_usd', 12, 2)->nullable();
            $table->unsignedSmallInteger('quota_alerted_threshold')->default(0);
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->uuid('quota_reservation_token')->nullable();
            $table->timestampTz('quota_reserved_at')->nullable();
            $table->decimal('quota_reserved_cost_usd', 16, 8)->nullable();
            $table->enum('quota_reserved_billed_to', ['platform', 'byok'])->nullable();
        });
        DB::statement('CREATE INDEX runs_active_quota_reservations ON runs (tenant_id, quota_reserved_at) WHERE quota_reserved_at IS NOT NULL');

        Schema::create('tenant_api_keys', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->enum('provider', ['openai', 'anthropic', 'google', 'perplexity']);
            $table->text('key');
            $table->timestampTz('last_verified_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'provider']);
        });
        TenantSchema::enableRowLevelSecurity('tenant_api_keys');
    }

    public function down(): void
    {
        TenantSchema::disableRowLevelSecurity('tenant_api_keys');
        Schema::dropIfExists('tenant_api_keys');

        DB::statement('DROP INDEX runs_active_quota_reservations');
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn(['quota_reservation_token', 'quota_reserved_at', 'quota_reserved_cost_usd', 'quota_reserved_billed_to']);
        });

        Schema::table('usage_monthly', function (Blueprint $table): void {
            $table->dropColumn(['byok_cost_usd', 'byok_budget_usd', 'quota_alerted_threshold']);
        });
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn(['monthly_call_quota_override', 'monthly_byok_budget_usd']);
        });
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('engine_max_frequencies');
        });
    }
};
