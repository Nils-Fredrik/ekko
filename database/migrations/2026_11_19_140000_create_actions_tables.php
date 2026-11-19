<?php

use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KINDS = ['engine_call', 'extraction', 'onboarding', 'pdf'];

    public function up(): void
    {
        // Actions (docs/spec.md 11.3, ADR 0034): what Ekko recommends a brand to do, with the evidence from the
        // database. System actions carry a fingerprint, so the next generation finds the same action again. A
        // user's own action may have no type.
        Schema::create('actions', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('market_id')->nullable();
            $table->string('type', 32)->nullable();
            $table->unsignedBigInteger('topic_id')->nullable();
            $table->string('title');
            $table->text('description');
            $table->jsonb('steps')->default('[]');
            $table->jsonb('evidence')->default('{}');
            $table->jsonb('competitor_evidence')->default('[]');
            $table->jsonb('engine_keys')->default('[]');
            $table->unsignedSmallInteger('impact_level')->default(1);
            $table->decimal('impact_score', 12, 2)->default(0);
            $table->string('status', 16)->default('new');
            $table->string('created_by', 8)->default('system');
            $table->string('text_source', 8)->default('template');
            // A BCP 47 tag as markets allow it (max 35), or nb/sv/en for a template text.
            $table->string('locale', 35);
            $table->string('fingerprint', 64)->nullable();
            $table->unsignedSmallInteger('unsupported_generations')->default(0);
            $table->timestampTz('last_generated_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'brand_id', 'status']);
            $table->index('market_id');
            $table->index('topic_id');
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'market_id'])->references(['tenant_id', 'id'])->on('markets')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE actions ADD FOREIGN KEY (tenant_id, topic_id) REFERENCES topics (tenant_id, id) ON DELETE SET NULL (topic_id)');
        DB::statement("ALTER TABLE actions ADD CONSTRAINT actions_type_check CHECK (type IN ('source_gap', 'owned_content', 'new_competitor', 'crawler_access', 'fact_correction') OR (type IS NULL AND created_by = 'user'))");
        DB::statement("ALTER TABLE actions ADD CONSTRAINT actions_status_check CHECK (status IN ('new', 'planned', 'in_progress', 'done', 'declined', 'expired'))");
        DB::statement("ALTER TABLE actions ADD CONSTRAINT actions_created_by_check CHECK (created_by IN ('system', 'user'))");
        DB::statement("ALTER TABLE actions ADD CONSTRAINT actions_text_source_check CHECK (text_source IN ('template', 'model', 'user'))");
        DB::statement('ALTER TABLE actions ADD CONSTRAINT actions_impact_level_check CHECK (impact_level BETWEEN 1 AND 5)');
        // A system action has a fingerprint, a user action never has one.
        DB::statement("ALTER TABLE actions ADD CONSTRAINT actions_fingerprint_check CHECK ((created_by = 'system') = (fingerprint IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX actions_tenant_fingerprint_unique ON actions (tenant_id, fingerprint) WHERE fingerprint IS NOT NULL');
        TenantSchema::enableRowLevelSecurity('actions');

        Schema::create('action_prompts', function (Blueprint $table): void {
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('action_id');
            $table->unsignedBigInteger('prompt_id');
            $table->primary(['action_id', 'prompt_id']);
            $table->index('prompt_id');
            $table->foreign(['tenant_id', 'action_id'])->references(['tenant_id', 'id'])->on('actions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'prompt_id'])->references(['tenant_id', 'id'])->on('prompts')->cascadeOnDelete();
        });
        TenantSchema::enableRowLevelSecurity('action_prompts');

        // One generation per brand and ISO week (tenant-local Monday). A second run in the same week refreshes the
        // actions but does not count towards expiry again.
        Schema::create('action_generations', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->date('week_start');
            $table->timestampTz('generated_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'brand_id', 'week_start']);
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
        });
        TenantSchema::enableRowLevelSecurity('action_generations');

        $this->usageKinds([...self::KINDS, 'actions']);
    }

    public function down(): void
    {
        // The old constraint has no 'actions'. The booked spend stays in anthropic_credit_spend (ADR 0023), so the
        // platform account keeps it; only the tenants' usage rows of this kind are removed.
        DB::statement("DELETE FROM usage_events WHERE kind = 'actions'");
        $this->usageKinds(self::KINDS);

        TenantSchema::disableRowLevelSecurity('action_generations');
        Schema::dropIfExists('action_generations');
        TenantSchema::disableRowLevelSecurity('action_prompts');
        Schema::dropIfExists('action_prompts');
        TenantSchema::disableRowLevelSecurity('actions');
        Schema::dropIfExists('actions');
    }

    /**
     * @param  list<string>  $kinds
     */
    private function usageKinds(array $kinds): void
    {
        DB::statement('ALTER TABLE usage_events DROP CONSTRAINT IF EXISTS usage_events_kind_check');
        $list = implode(', ', array_map(fn (string $kind): string => "'{$kind}'", $kinds));
        DB::statement("ALTER TABLE usage_events ADD CONSTRAINT usage_events_kind_check CHECK (kind IN ({$list}))");
    }
};
