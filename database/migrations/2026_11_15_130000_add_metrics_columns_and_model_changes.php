<?php

use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Counters the formulas in docs/spec.md 9 need that 6.4 does not list (ADR 0012).
        Schema::table('agg_daily', function (Blueprint $table): void {
            $table->unsignedBigInteger('list_responses')->default(0)->after('count_position');
            $table->unsignedBigInteger('first_place')->default(0)->after('list_responses');
            $table->unsignedBigInteger('count_sentiment')->default(0)->after('sum_sentiment');
            $table->unsignedBigInteger('competitor_only_responses')->default(0)->after('own_cited');

            // Dashboard filters: brand + market + engine over a period, and one prompt over a period.
            $table->index(['tenant_id', 'brand_id', 'market_id', 'engine_key', 'date'], 'agg_daily_filter_index');
            $table->index(['tenant_id', 'brand_id', 'prompt_id', 'date'], 'agg_daily_prompt_index');
        });

        Schema::table('agg_source_daily', function (Blueprint $table): void {
            $table->unsignedBigInteger('gap_responses')->default(0)->after('responses_with_domain');

            $table->index(['tenant_id', 'brand_id', 'market_id', 'engine_key', 'date'], 'agg_source_daily_filter_index');
            $table->index(['tenant_id', 'brand_id', 'domain', 'date'], 'agg_source_daily_domain_index');
        });

        Schema::create('model_changes', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->string('engine_key');
            $table->date('changed_on');
            $table->string('from_model');
            $table->string('to_model');
            $table->timestampTz('changed_at');
            $table->timestampsTz();
            $table->foreign('engine_key')->references('key')->on('engines');
            $table->unique(['tenant_id', 'engine_key', 'changed_on'], 'model_changes_day_unique');
            $table->index(['tenant_id', 'changed_on']);
        });
        TenantSchema::enableRowLevelSecurity('model_changes');
    }

    public function down(): void
    {
        TenantSchema::disableRowLevelSecurity('model_changes');
        Schema::dropIfExists('model_changes');

        Schema::table('agg_source_daily', function (Blueprint $table): void {
            $table->dropIndex('agg_source_daily_filter_index');
            $table->dropIndex('agg_source_daily_domain_index');
            $table->dropColumn('gap_responses');
        });

        Schema::table('agg_daily', function (Blueprint $table): void {
            $table->dropIndex('agg_daily_filter_index');
            $table->dropIndex('agg_daily_prompt_index');
            $table->dropColumn(['list_responses', 'first_place', 'count_sentiment', 'competitor_only_responses']);
        });
    }
};
