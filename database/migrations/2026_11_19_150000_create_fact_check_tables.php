<?php

use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KINDS = ['engine_call', 'extraction', 'onboarding', 'pdf', 'actions'];

    public function up(): void
    {
        // Facts about a brand (docs/spec.md 11.6, ADR 0036): what the tenant admin says is true. Only active facts
        // are checked against the answers.
        Schema::create('brand_facts', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->string('text', 300);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'brand_id', 'is_active']);
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
        });
        TenantSchema::enableRowLevelSecurity('brand_facts');

        // A claim about the brand in one answer, judged against one active fact. source_citation_ids are the
        // citations of the same answer that the model says repeat the claim (marked sources).
        Schema::create('claims', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('response_id');
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('fact_id')->nullable();
            $table->string('text', 500);
            $table->string('quote', 300)->nullable();
            $table->string('verdict', 16);
            $table->jsonb('source_citation_ids')->default('[]');
            $table->timestampsTz();

            $table->index(['tenant_id', 'brand_id', 'verdict']);
            $table->index('response_id');
            $table->index('fact_id');
            $table->foreign(['tenant_id', 'response_id'])->references(['tenant_id', 'id'])->on('responses')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
        });
        // A claim goes with its fact: it was judged against the text the fact had.
        DB::statement('ALTER TABLE claims ADD FOREIGN KEY (tenant_id, fact_id) REFERENCES brand_facts (tenant_id, id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_verdict_check CHECK (verdict IN ('supported', 'contradicted', 'unrelated'))");
        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_fact_check CHECK (fact_id IS NOT NULL OR verdict = 'unrelated')");
        TenantSchema::enableRowLevelSecurity('claims');

        // Set when the fact check has judged the answer, so it is judged once (ADR 0036).
        Schema::table('responses', function (Blueprint $table): void {
            $table->timestampTz('facts_checked_at')->nullable();
        });

        // The fact check is its own kind of usage: a Sonnet call paid by the platform (ADR 0036).
        $this->usageKinds([...self::KINDS, 'factcheck']);
    }

    public function down(): void
    {
        // Booked usage is billing data: refuse to roll back rather than delete it.
        if (DB::table('usage_events')->where('kind', 'factcheck')->exists()) {
            throw new RuntimeException('usage_events has rows of kind factcheck; they are billing data and are not deleted by a rollback.');
        }
        $this->usageKinds(self::KINDS);
        Schema::table('responses', fn (Blueprint $table) => $table->dropColumn('facts_checked_at'));

        TenantSchema::disableRowLevelSecurity('claims');
        Schema::dropIfExists('claims');
        TenantSchema::disableRowLevelSecurity('brand_facts');
        Schema::dropIfExists('brand_facts');
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
