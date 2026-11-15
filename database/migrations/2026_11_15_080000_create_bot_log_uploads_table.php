<?php

use App\Tenancy\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_log_uploads', function (Blueprint $table): void {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->unsignedBigInteger('brand_id');
            $table->string('domain');
            $table->text('payload')->nullable();
            $table->string('status')->default('pending');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'brand_id'])->references(['tenant_id', 'id'])->on('brands')->cascadeOnDelete();
            $table->index(['tenant_id', 'brand_id', 'id']);
        });
        TenantSchema::enableRowLevelSecurity('bot_log_uploads');
    }

    public function down(): void
    {
        TenantSchema::disableRowLevelSecurity('bot_log_uploads');
        Schema::dropIfExists('bot_log_uploads');
    }
};
