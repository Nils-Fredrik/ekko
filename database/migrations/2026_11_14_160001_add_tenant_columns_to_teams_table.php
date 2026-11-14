<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tenant is a Jetstream team (docs/spec.md 5.1 and 6.1).
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('partner_team_id')->nullable()->after('personal_team')
                ->constrained('teams')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->after('partner_team_id')
                ->constrained('plans')->nullOnDelete();
            $table->string('status')->default('active')->after('plan_id');
            $table->string('default_locale', 10)->default('nb')->after('status');
            $table->string('timezone')->default('Europe/Oslo')->after('default_locale');
            $table->jsonb('branding')->nullable()->after('timezone');
            $table->text('slack_webhook_url')->nullable()->after('branding');
            $table->boolean('byok_enabled')->default(false)->after('slack_webhook_url');

            $table->index('partner_team_id');
            $table->index('plan_id');
            $table->index('status');
        });

        DB::statement("ALTER TABLE teams ADD CONSTRAINT teams_status_check CHECK (status IN ('active', 'paused', 'closed'))");
        DB::statement('ALTER TABLE teams ADD CONSTRAINT teams_partner_not_self_check CHECK (partner_team_id IS NULL OR partner_team_id <> id)');
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_team_id');
            $table->dropConstrainedForeignId('plan_id');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'default_locale', 'timezone', 'branding', 'slack_webhook_url', 'byok_enabled']);
        });

        DB::statement('ALTER TABLE teams DROP CONSTRAINT IF EXISTS teams_status_check');
        DB::statement('ALTER TABLE teams DROP CONSTRAINT IF EXISTS teams_partner_not_self_check');
    }
};
