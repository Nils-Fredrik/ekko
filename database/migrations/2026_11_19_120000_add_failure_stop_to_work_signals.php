<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stops a work signal after a long failing streak (docs/spec.md 7.1, ADR 0032, T40-F2-F1). The table keeps its row-level
 * security; only columns and a check are added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_signals', function (Blueprint $table): void {
            // Start of the current failing streak; a streak longer than ekko.work.stop_after_seconds stops the signal.
            $table->timestampTz('failing_since')->nullable();
            // Stopped after failing too long: not retried and not recovered until a new signal or ekko:work:retry.
            $table->timestampTz('stopped_at')->nullable();
        });

        DB::statement('ALTER TABLE work_signals ADD CONSTRAINT work_signals_stopped CHECK (stopped_at IS NULL OR (lease_token IS NULL AND version > processed_version))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_signals DROP CONSTRAINT IF EXISTS work_signals_stopped');
        Schema::table('work_signals', function (Blueprint $table): void {
            $table->dropColumn(['failing_since', 'stopped_at']);
        });
    }
};
