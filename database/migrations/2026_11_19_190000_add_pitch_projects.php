<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->boolean('is_pitch_plan')->default(false);
        });
        DB::statement('CREATE UNIQUE INDEX plans_one_pitch_plan ON plans (is_pitch_plan) WHERE is_pitch_plan');
        DB::table('plans')->insert([
            'name' => '__ekko_internal_pitch_v1',
            'is_pitch_plan' => true,
            'max_brands' => 1,
            'max_markets_per_brand' => 1,
            'max_prompts_per_market' => 25,
            'allowed_engines' => json_encode(['chatgpt_api', 'gemini_api', 'claude_haiku'], JSON_THROW_ON_ERROR),
            'engine_max_frequencies' => '{}',
            'max_frequency' => 'daily',
            'max_repetitions' => 1,
            'monthly_call_quota' => 4000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('kind')->default('customer');
            $table->timestampTz('pitch_expires_at')->nullable();
            $table->timestampTz('pitch_converted_at')->nullable();
            $table->index(['partner_team_id', 'kind', 'pitch_expires_at']);
        });
        DB::statement("ALTER TABLE teams ADD CONSTRAINT teams_kind_check CHECK (kind IN ('customer', 'pitch'))");
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->string('project_kind')->default('customer');
            $table->index(['tenant_id', 'project_kind', 'occurred_at']);
        });
        DB::statement("ALTER TABLE usage_events ADD CONSTRAINT usage_events_project_kind_check CHECK (project_kind IN ('customer', 'pitch'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE usage_events DROP CONSTRAINT IF EXISTS usage_events_project_kind_check');
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'project_kind', 'occurred_at']);
            $table->dropColumn('project_kind');
        });
        DB::statement('ALTER TABLE teams DROP CONSTRAINT IF EXISTS teams_kind_check');
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropIndex(['partner_team_id', 'kind', 'pitch_expires_at']);
            $table->dropColumn(['kind', 'pitch_expires_at', 'pitch_converted_at']);
        });
        DB::table('plans')->where('is_pitch_plan', true)->delete();
        DB::statement('DROP INDEX IF EXISTS plans_one_pitch_plan');
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('is_pitch_plan'));
    }
};
