<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompts', function (Blueprint $table): void {
            $table->timestampTz('archived_at')->nullable();
            $table->index(['market_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('prompts', function (Blueprint $table): void {
            $table->dropIndex(['market_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
