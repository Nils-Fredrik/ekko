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
