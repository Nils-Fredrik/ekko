<?php

use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('brand_id');
            $table->string('page', 80);
            $table->string('name', 80);
            $table->jsonb('filters');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id', 'brand_id', 'page', 'name']);
        });
        TenantSchema::enableRowLevelSecurity('saved_views');
    }

    public function down(): void
    {
        TenantSchema::disableRowLevelSecurity('saved_views');
        Schema::dropIfExists('saved_views');
    }
};
