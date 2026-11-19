<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL denotes a historical row that has not yet been recomputed. It
        // must not be interpreted as an observed distribution of zeroes.
        Schema::table('agg_daily', function (Blueprint $table): void {
            $table->jsonb('sentiment_distribution')->nullable();
        });
        Schema::table('agg_source_daily', function (Blueprint $table): void {
            $table->jsonb('citation_distribution')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agg_source_daily', fn (Blueprint $table) => $table->dropColumn('citation_distribution'));
        Schema::table('agg_daily', fn (Blueprint $table) => $table->dropColumn('sentiment_distribution'));
    }
};
