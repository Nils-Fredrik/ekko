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
        Schema::create('run_batches', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('market_id');
            $table->timestampTz('scheduled_for');
            $table->string('status')->default('queued');
            $table->unsignedInteger('planned_runs')->default(0);
            $table->unsignedInteger('completed_runs')->default(0);
            $table->unsignedInteger('failed_runs')->default(0);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'market_id'])->references(['tenant_id', 'id'])->on('markets')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['market_id', 'scheduled_for']);
        });
        TenantSchema::enableRowLevelSecurity('run_batches');

        Schema::create('runs', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('run_batch_id');
            $table->unsignedBigInteger('prompt_id');
            $table->string('engine_key');
            $table->unsignedSmallInteger('repetition')->default(1);
            $table->enum('status', ['queued', 'running', 'submitted', 'collected', 'extracted', 'failed', 'skipped_quota'])->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'run_batch_id'])->references(['tenant_id', 'id'])->on('run_batches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'prompt_id'])->references(['tenant_id', 'id'])->on('prompts')->cascadeOnDelete();
            $table->foreign('engine_key')->references('key')->on('engines');
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'run_batch_id', 'prompt_id', 'engine_key', 'repetition'], 'runs_schedule_unique');
            $table->index(['prompt_id', 'engine_key', 'status']);
        });
        TenantSchema::enableRowLevelSecurity('runs');
        DB::statement('ALTER TABLE runs ADD CONSTRAINT runs_repetition_range CHECK (repetition BETWEEN 1 AND 3)');

        Schema::create('responses', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('run_id');
            $table->string('engine_key');
            $table->string('model_id_reported')->nullable();
            $table->text('answer_text');
            $table->string('answer_language')->nullable();
            $table->text('raw_uri')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('search_calls')->default(0);
            $table->decimal('cost_usd', 16, 8)->default(0);
            $table->timestampTz('collected_at');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'run_id'])->references(['tenant_id', 'id'])->on('runs')->cascadeOnDelete();
            $table->foreign('engine_key')->references('key')->on('engines');
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'run_id']);
            $table->index(['tenant_id', 'collected_at']);
        });
        TenantSchema::enableRowLevelSecurity('responses');

        Schema::create('mentions', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('response_id');
            $table->enum('entity_type', ['brand', 'competitor', 'unknown']);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('surface_name');
            $table->unsignedInteger('first_char_offset')->nullable();
            $table->unsignedInteger('list_position')->nullable();
            $table->boolean('is_recommended')->default(false);
            $table->decimal('sentiment', 4, 3)->nullable();
            $table->enum('detection', ['deterministic', 'llm']);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'response_id'])->references(['tenant_id', 'id'])->on('responses')->cascadeOnDelete();
            $table->index('response_id');
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
        });
        TenantSchema::enableRowLevelSecurity('mentions');
        DB::statement("ALTER TABLE mentions ADD CONSTRAINT mentions_entity_presence CHECK ((entity_type = 'unknown') = (entity_id IS NULL))");
        DB::statement('ALTER TABLE mentions ADD CONSTRAINT mentions_sentiment_range CHECK (sentiment BETWEEN -1 AND 1)');

        Schema::create('citations', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('response_id');
            $table->text('url');
            $table->text('url_normalized');
            $table->string('domain');
            $table->enum('ownership', ['own', 'competitor', 'third_party']);
            $table->unsignedBigInteger('competitor_id')->nullable();
            $table->enum('source_type', ['news', 'review', 'forum', 'wiki', 'marketplace', 'social', 'gov', 'other'])->nullable();
            $table->unsignedInteger('rank_in_answer')->nullable();
            $table->boolean('from_annotation')->default(false);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'response_id'])->references(['tenant_id', 'id'])->on('responses')->cascadeOnDelete();
            $table->index('response_id');
            $table->index('competitor_id');
            $table->index(['tenant_id', 'domain']);
        });
        TenantSchema::enableRowLevelSecurity('citations');

        DB::statement('ALTER TABLE citations ADD FOREIGN KEY (tenant_id, competitor_id) REFERENCES competitors (tenant_id, id) ON DELETE SET NULL (competitor_id)');

        Schema::create('extraction_jobs', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->string('provider_batch_id');
            $table->string('status');
            $table->jsonb('response_ids');
            $table->timestampTz('submitted_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'provider_batch_id']);
            $table->index(['tenant_id', 'status']);
        });
        TenantSchema::enableRowLevelSecurity('extraction_jobs');
    }

    public function down(): void
    {
        TenantSchema::disableRowLevelSecurity('extraction_jobs');
        Schema::dropIfExists('extraction_jobs');
        TenantSchema::disableRowLevelSecurity('citations');
        Schema::dropIfExists('citations');
        TenantSchema::disableRowLevelSecurity('mentions');
        Schema::dropIfExists('mentions');
        TenantSchema::disableRowLevelSecurity('responses');
        Schema::dropIfExists('responses');
        TenantSchema::disableRowLevelSecurity('runs');
        Schema::dropIfExists('runs');
        TenantSchema::disableRowLevelSecurity('run_batches');
        Schema::dropIfExists('run_batches');
    }
};
