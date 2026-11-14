<?php

use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agg_daily', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('market_id');
            $table->string('engine_key');
            $table->unsignedBigInteger('prompt_id');
            $table->date('date');
            $table->unsignedBigInteger('responses')->default(0);
            $table->unsignedBigInteger('brand_mentioned')->default(0);
            $table->unsignedBigInteger('brand_recommended')->default(0);
            $table->unsignedBigInteger('sum_position')->default(0);
            $table->unsignedBigInteger('count_position')->default(0);
            $table->decimal('sum_sentiment', 16, 3)->default(0);
            $table->unsignedBigInteger('own_cited')->default(0);
            $table->jsonb('competitor_mentions')->default('{}');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'market_id'])->references(['tenant_id', 'id'])->on('markets')->cascadeOnDelete();
            $table->foreign('engine_key')->references('key')->on('engines');
            $table->foreign(['tenant_id', 'prompt_id'])->references(['tenant_id', 'id'])->on('prompts')->cascadeOnDelete();
            $table->unique(['tenant_id', 'brand_id', 'market_id', 'engine_key', 'prompt_id', 'date'], 'agg_daily_dimensions_unique');
            $table->index(['tenant_id', 'brand_id', 'date']);
        });
        TenantSchema::enableRowLevelSecurity('agg_daily');

        Schema::create('agg_source_daily', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('market_id');
            $table->string('engine_key');
            $table->string('domain');
            $table->date('date');
            $table->unsignedBigInteger('citations')->default(0);
            $table->unsignedBigInteger('responses_with_domain')->default(0);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'market_id'])->references(['tenant_id', 'id'])->on('markets')->cascadeOnDelete();
            $table->foreign('engine_key')->references('key')->on('engines');
            $table->unique(['tenant_id', 'brand_id', 'market_id', 'engine_key', 'domain', 'date'], 'agg_source_daily_dimensions_unique');
            $table->index(['tenant_id', 'brand_id', 'date']);
        });
        TenantSchema::enableRowLevelSecurity('agg_source_daily');

        Schema::create('bot_hits_daily', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->string('domain');
            $table->string('bot');
            $table->date('date');
            $table->unsignedBigInteger('hits')->default(0);
            $table->unsignedBigInteger('unique_paths')->default(0);
            $table->jsonb('top_paths')->default('[]');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'brand_id', 'domain', 'bot', 'date'], 'bot_hits_daily_dimensions_unique');
            $table->index(['tenant_id', 'brand_id', 'date']);
        });
        TenantSchema::enableRowLevelSecurity('bot_hits_daily');

        Schema::create('ai_referrals_daily', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->string('source');
            $table->date('date');
            $table->unsignedBigInteger('sessions')->default(0);
            $table->unsignedBigInteger('engaged_sessions')->default(0);
            $table->decimal('conversions', 16, 4)->default(0);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'brand_id', 'source', 'date']);
            $table->index(['tenant_id', 'brand_id', 'date']);
        });
        TenantSchema::enableRowLevelSecurity('ai_referrals_daily');
    }

    public function down(): void
    {
        TenantSchema::disableRowLevelSecurity('ai_referrals_daily');
        Schema::dropIfExists('ai_referrals_daily');
        TenantSchema::disableRowLevelSecurity('bot_hits_daily');
        Schema::dropIfExists('bot_hits_daily');
        TenantSchema::disableRowLevelSecurity('agg_source_daily');
        Schema::dropIfExists('agg_source_daily');
        TenantSchema::disableRowLevelSecurity('agg_daily');
        Schema::dropIfExists('agg_daily');
    }
};
