<?php

use App\MeasurementPlan\StandardPlan;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The brand's standard measurement plan and the market's inheritance (docs/spec.md 6.2 and 7.6, T53, ADR 0042).
 *
 * Existing markets keep their rows in market_engines untouched and become explicit own choices, so the run
 * pattern does not change: off engines, separate core rules and markets without engines included. Every
 * existing brand gets the standard plan a new brand in its tenant's package would get (all off without one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_plan_engines', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->string('engine_key');
            $table->string('frequency', 32);
            $table->string('prompt_scope', 8)->default('all');
            $table->unsignedSmallInteger('repetitions')->default(1);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->foreign('engine_key')->references('key')->on('engines');
            $table->unique(['tenant_id', 'brand_id', 'engine_key', 'prompt_scope']);
        });
        TenantSchema::enableRowLevelSecurity('brand_plan_engines');
        DB::statement("ALTER TABLE brand_plan_engines ADD CONSTRAINT brand_plan_engines_frequency_check CHECK (frequency IN ('off', 'monthly', 'every_21_days', 'every_14_days', 'weekly', 'daily'))");
        DB::statement("ALTER TABLE brand_plan_engines ADD CONSTRAINT brand_plan_engines_prompt_scope_check CHECK (prompt_scope IN ('all', 'core'))");
        DB::statement('ALTER TABLE brand_plan_engines ADD CONSTRAINT brand_plan_engines_repetitions_range CHECK (repetitions BETWEEN 1 AND 3)');

        // A constant default is a catalogue change in PostgreSQL: existing rows read 'own' without a rewrite or
        // an RLS context. Markets created by code that predates the plan also stay own with their own rows.
        DB::statement("ALTER TABLE markets ADD COLUMN plan_mode varchar(16) NOT NULL DEFAULT 'own'");
        DB::statement("ALTER TABLE markets ADD CONSTRAINT markets_plan_mode_check CHECK (plan_mode IN ('inherit', 'own'))");

        $now = now();
        foreach (Team::query()->orderBy('id')->get() as $tenant) {
            $rules = StandardPlan::forTenant($tenant);
            app(TenantRls::class)->run([$tenant->id], function () use ($tenant, $rules, $now): void {
                foreach (DB::table('brands')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('id') as $brandId) {
                    DB::table('brand_plan_engines')->insertOrIgnore(array_map(fn (array $rule): array => $rule + [
                        'tenant_id' => $tenant->id,
                        'brand_id' => $brandId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $rules));
                }
            });
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE markets DROP CONSTRAINT IF EXISTS markets_plan_mode_check');
        DB::statement('ALTER TABLE markets DROP COLUMN IF EXISTS plan_mode');
        TenantSchema::disableRowLevelSecurity('brand_plan_engines');
        Schema::dropIfExists('brand_plan_engines');
    }
};
