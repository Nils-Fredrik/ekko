<?php

use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id']);
            $table->jsonb('web_snapshot')->nullable();
        });
        Schema::create('report_links', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('report_id');
            $table->char('public_id', 32)->unique();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('views')->default(0);
            $table->timestampTz('last_viewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'report_id'])->references(['tenant_id', 'id'])->on('reports')->cascadeOnDelete();
        });
        TenantSchema::enableRowLevelSecurity('report_links');
        Schema::create('report_link_views', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('report_link_id');
            $table->timestampTz('viewed_at');
            $table->foreign(['tenant_id', 'report_link_id'])->references(['tenant_id', 'id'])->on('report_links')->cascadeOnDelete();
        });
        TenantSchema::enableRowLevelSecurity('report_link_views');
    }

    public function down(): void
    {
        TenantSchema::disableRowLevelSecurity('report_link_views');
        Schema::dropIfExists('report_link_views');
        TenantSchema::disableRowLevelSecurity('report_links');
        Schema::dropIfExists('report_links');
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropColumn('web_snapshot');
        });
    }
};
