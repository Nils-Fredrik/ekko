<?php

use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decisions on unknown brands (docs/ux-arbeidsflate.md 6.3, ADR 0043), the fixed color place of competitors
 * (competitors.position) and when a brand's queue of unknown brands was last emptied (brands.entities_reviewed_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_decisions', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->string('name_key', 200);
            $table->string('display_name', 200);
            $table->jsonb('variants');
            $table->string('decision', 16);
            $table->string('target_type', 16)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->boolean('created_competitor')->default(false);
            $table->jsonb('alias_ids');
            $table->integer('reclassified_mentions')->default(0);
            // The mentions the decision moved, so an undo moves back exactly these (ADR 0043).
            $table->jsonb('mention_ids')->default(DB::raw("'[]'::jsonb"));
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'brand_id', 'name_key']);
        });
        TenantSchema::enableRowLevelSecurity('entity_decisions');
        DB::statement("ALTER TABLE entity_decisions ADD CONSTRAINT entity_decisions_decision_check CHECK (decision IN ('competitor', 'other', 'alias', 'ignored'))");
        DB::statement("ALTER TABLE entity_decisions ADD CONSTRAINT entity_decisions_target_type_check CHECK (target_type IS NULL OR target_type IN ('brand', 'competitor'))");
        DB::statement('ALTER TABLE entity_decisions ADD CONSTRAINT entity_decisions_target_presence CHECK ((target_type IS NULL) = (target_id IS NULL))');

        Schema::table('brands', function (Blueprint $table): void {
            $table->timestampTz('entities_reviewed_at')->nullable();
        });
        Schema::table('competitors', function (Blueprint $table): void {
            $table->integer('position')->default(0);
        });

        // The place follows today's color order (name, id), so no competitor changes color. Archived and inactive
        // competitors are numbered too; the color place counts only the active ones.
        foreach (Team::query()->orderBy('id')->pluck('id') as $tenantId) {
            app(TenantRls::class)->run([(int) $tenantId], function () use ($tenantId): void {
                DB::statement(<<<'SQL'
                    UPDATE competitors SET position = ordered.place
                    FROM (
                        SELECT id, row_number() OVER (PARTITION BY brand_id ORDER BY name, id) AS place
                        FROM competitors WHERE tenant_id = ?
                    ) AS ordered
                    WHERE competitors.id = ordered.id AND competitors.tenant_id = ?
                    SQL, [(int) $tenantId, (int) $tenantId]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('competitors', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('entities_reviewed_at');
        });
        TenantSchema::disableRowLevelSecurity('entity_decisions');
        Schema::dropIfExists('entity_decisions');
    }
};
