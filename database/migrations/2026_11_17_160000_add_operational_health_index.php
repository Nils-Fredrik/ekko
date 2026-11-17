<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->index(['tenant_id', 'finished_at'], 'runs_tenant_finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropIndex('runs_tenant_finished_at');
        });
    }
};
