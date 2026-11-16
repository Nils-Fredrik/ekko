<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a tenant was closed (T39-F6). The tenant and its data are deleted Team::PURGE_AFTER_DAYS later
 * (docs/spec.md 16). A tenant closed before this column existed has none and is not deleted automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->timestampTz('closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('closed_at');
        });
    }
};
