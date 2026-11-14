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
        Schema::create('engines', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->enum('family', ['chatgpt', 'gemini', 'google_aio', 'perplexity', 'claude']);
            $table->enum('method', ['api', 'ui_scrape', 'serp']);
            $table->string('provider');
            $table->string('model_id')->nullable();
            $table->jsonb('config')->default('{}');
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
        });

        Schema::create('engine_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('engine_key');
            $table->foreign('engine_key')->references('key')->on('engines');
            $table->timestampTz('valid_from');
            $table->decimal('price_per_call_usd', 16, 8)->default(0);
            $table->decimal('price_per_1k_input_tokens_usd', 16, 8)->default(0);
            $table->decimal('price_per_1k_output_tokens_usd', 16, 8)->default(0);
            $table->decimal('price_per_search_usd', 16, 8)->default(0);
            $table->timestampsTz();
            $table->unique(['engine_key', 'valid_from']);
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->string('name');
            $table->string('slug');
            $table->string('primary_domain');
            $table->string('status')->default('active');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'slug']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('brand_aliases', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->string('alias');
            $table->enum('match_mode', ['exact_word', 'case_insensitive', 'regex']);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'brand_id', 'alias', 'match_mode'], 'brand_aliases_match_unique');
        });

        Schema::create('brand_domains', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->string('domain');
            $table->boolean('include_subdomains')->default(false);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'brand_id', 'domain']);
        });

        Schema::create('competitors', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index('brand_id');
        });

        Schema::create('competitor_aliases', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('competitor_id');
            $table->string('alias');
            $table->enum('match_mode', ['exact_word', 'case_insensitive', 'regex']);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'competitor_id'])->references(['tenant_id', 'id'])->on('competitors')->cascadeOnDelete();
            $table->unique(['tenant_id', 'competitor_id', 'alias', 'match_mode'], 'competitor_aliases_match_unique');
        });

        Schema::create('competitor_domains', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('competitor_id');
            $table->string('domain');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'competitor_id'])->references(['tenant_id', 'id'])->on('competitors')->cascadeOnDelete();
            $table->unique(['tenant_id', 'competitor_id', 'domain']);
        });

        Schema::create('markets', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->char('country', 2);
            $table->string('language');
            $table->string('location_label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index('brand_id');
        });

        Schema::create('market_engines', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('market_id');
            $table->string('engine_key');
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'off']);
            $table->enum('prompt_scope', ['all', 'core'])->default('all');
            $table->unsignedSmallInteger('repetitions')->default(1);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'market_id'])->references(['tenant_id', 'id'])->on('markets')->cascadeOnDelete();
            $table->foreign('engine_key')->references('key')->on('engines');
            $table->unique(['tenant_id', 'market_id', 'engine_key', 'prompt_scope']);
        });
        DB::statement('ALTER TABLE market_engines ADD CONSTRAINT market_engines_repetitions_range CHECK (repetitions BETWEEN 1 AND 3)');

        Schema::create('topics', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->string('name');
            $table->string('cross_market_key')->nullable();
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['brand_id', 'cross_market_key']);
        });

        Schema::create('prompts', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('topic_id')->nullable();
            $table->text('text');
            $table->enum('intent', ['category', 'comparison', 'brand', 'problem', 'local']);
            $table->enum('funnel_stage', ['awareness', 'consideration', 'decision']);
            $table->enum('priority', ['core', 'standard'])->default('standard');
            $table->boolean('is_active')->default(true);
            $table->char('text_hash', 64);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'market_id'])->references(['tenant_id', 'id'])->on('markets')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'market_id', 'text_hash']);
            $table->index('topic_id');
        });
        DB::statement('ALTER TABLE prompts ADD FOREIGN KEY (tenant_id, topic_id) REFERENCES topics (tenant_id, id) ON DELETE SET NULL (topic_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prompts');
        Schema::dropIfExists('topics');
        Schema::dropIfExists('market_engines');
        Schema::dropIfExists('markets');
        Schema::dropIfExists('competitor_domains');
        Schema::dropIfExists('competitor_aliases');
        Schema::dropIfExists('competitors');
        Schema::dropIfExists('brand_domains');
        Schema::dropIfExists('brand_aliases');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('engine_prices');
        Schema::dropIfExists('engines');
    }
};
