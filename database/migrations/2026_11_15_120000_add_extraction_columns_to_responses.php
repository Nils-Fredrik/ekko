<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responses', function (Blueprint $table): void {
            // Result of model-based extraction (docs/spec.md 8.2, ADR 0011). llm_failed is set when the
            // model stage failed twice and only the deterministic results were kept.
            $table->enum('answer_type', ['list', 'single_recommendation', 'comparison', 'informational', 'refusal'])->nullable();
            $table->boolean('llm_failed')->default(false);
        });

        DB::statement("ALTER TABLE extraction_jobs ADD CONSTRAINT extraction_jobs_status_values CHECK (status IN ('submitted', 'completed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE extraction_jobs DROP CONSTRAINT extraction_jobs_status_values');

        Schema::table('responses', function (Blueprint $table): void {
            $table->dropColumn(['answer_type', 'llm_failed']);
        });
    }
};
