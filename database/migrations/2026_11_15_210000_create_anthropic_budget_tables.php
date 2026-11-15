<?php

use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-wide account state, without tenant content or credentials (ADR 0023).
        Schema::create('anthropic_budget_cycles', function (Blueprint $table): void {
            $table->date('cycle_start')->primary();
            $table->boolean('exhausted')->default(false);
            $table->jsonb('forecast')->nullable();
            $table->timestampTz('planned_at')->nullable();
        });
        Schema::create('anthropic_credit_spend', function (Blueprint $table): void {
            $table->unsignedBigInteger('usage_event_id')->primary();
            $table->timestampTz('occurred_at')->index();
            $table->decimal('cost_usd', 16, 8);
        });
        app(TenantRls::class)->run(Team::query()->pluck('id')->all(), function (): void {
            DB::statement("INSERT INTO anthropic_credit_spend (usage_event_id, occurred_at, cost_usd) SELECT id, occurred_at, cost_usd FROM usage_events WHERE billed_to = 'platform' AND (engine_key IN ('claude_haiku', 'claude_sonnet') OR kind IN ('extraction', 'onboarding'))");
        });
        Schema::create('anthropic_budget_holds', function (Blueprint $table): void {
            $table->string('operation');
            $table->date('cycle_start');
            $table->primary(['cycle_start', 'operation']);
            $table->decimal('cost_usd', 16, 8);
        });
        Schema::create('anthropic_budget_plans', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->date('day');
            $table->jsonb('additions');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'day']);
        });
        TenantSchema::enableRowLevelSecurity('anthropic_budget_plans');
        Schema::create('anthropic_insight_allocations', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->date('cycle_start');
            $table->decimal('cost_usd', 16, 8);
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'brand_id', 'cycle_start'], 'anthropic_insight_once');
        });
        TenantSchema::enableRowLevelSecurity('anthropic_insight_allocations');
    }

    public function down(): void
    {
        TenantSchema::disableRowLevelSecurity('anthropic_budget_plans');
        Schema::dropIfExists('anthropic_budget_plans');
        Schema::dropIfExists('anthropic_budget_holds');
        Schema::dropIfExists('anthropic_budget_cycles');
        Schema::dropIfExists('anthropic_credit_spend');
        TenantSchema::disableRowLevelSecurity('anthropic_insight_allocations');
        Schema::dropIfExists('anthropic_insight_allocations');
    }
};
