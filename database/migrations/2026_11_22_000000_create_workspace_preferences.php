<?php

use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_brand_preferences', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('brand_id');
            $table->timestampTz('checklist_hidden_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id', 'brand_id']);
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
        });
        TenantSchema::enableRowLevelSecurity('user_brand_preferences');
        Schema::table('integrations', fn (Blueprint $table) => $table->timestampTz('last_error_at')->nullable());
        foreach (DB::table('teams')->pluck('id') as $tenantId) {
            app(TenantRls::class)->run([(int) $tenantId], fn () => DB::table('integrations')
                ->where('tenant_id', $tenantId)->where('status', 'error')->update(['last_error_at' => DB::raw('updated_at')]));
        }
        Schema::table('report_schedules', fn (Blueprint $table) => $table->softDeletesTz());
    }

    public function down(): void
    {
        Schema::table('integrations', fn (Blueprint $table) => $table->dropColumn('last_error_at'));
        Schema::table('report_schedules', fn (Blueprint $table) => $table->dropSoftDeletes());
        TenantSchema::disableRowLevelSecurity('user_brand_preferences');
        Schema::dropIfExists('user_brand_preferences');
    }
};
