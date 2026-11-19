<?php

use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KINDS = ['engine_call', 'extraction', 'onboarding', 'pdf', 'actions', 'factcheck'];

    public function up(): void
    {
        // Insight text per day and filter combination (docs/spec.md 14.2, ADR 0038). The text is a template with
        // placeholders; the figures are filled in from the database when the text is shown.
        Schema::create('insights', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('scope', 16);
            $table->date('local_date');
            $table->string('filter_key', 64);
            $table->jsonb('filter')->default('{}');
            $table->string('locale', 8);
            $table->string('status', 16);
            $table->string('reason', 24)->nullable();
            $table->text('template')->nullable();
            $table->jsonb('directions')->default('{}');
            $table->string('model', 64)->nullable();
            $table->unsignedBigInteger('usage_event_id')->nullable();
            $table->timestampTz('attempted_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'filter_key', 'local_date']);
            $table->index(['tenant_id', 'attempted_at']);
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE insights ADD CONSTRAINT insights_scope_check CHECK (scope IN ('brand', 'overview'))");
        DB::statement("ALTER TABLE insights ADD CONSTRAINT insights_status_check CHECK (status IN ('written', 'unavailable'))");
        DB::statement("ALTER TABLE insights ADD CONSTRAINT insights_scope_brand_check CHECK ((scope = 'brand') = (brand_id IS NOT NULL))");
        TenantSchema::enableRowLevelSecurity('insights');

        $this->usageKinds([...self::KINDS, 'insight']);
    }

    public function down(): void
    {
        // Booked usage is billing data: refuse to roll back rather than delete it.
        if (DB::table('usage_events')->where('kind', 'insight')->exists()) {
            throw new RuntimeException('usage_events has rows of kind insight; they are billing data and are not deleted by a rollback.');
        }
        $this->usageKinds(self::KINDS);
        TenantSchema::disableRowLevelSecurity('insights');
        Schema::dropIfExists('insights');
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
