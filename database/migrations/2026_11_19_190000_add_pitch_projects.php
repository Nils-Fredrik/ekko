<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }
};
