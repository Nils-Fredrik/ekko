<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// U29: the quote from the answer that explains the sentiment of a mention (docs/spec.md 8.2, ADR 0011 point 11).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mentions', function (Blueprint $table): void {
            $table->string('sentiment_quote', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mentions', function (Blueprint $table): void {
            $table->dropColumn('sentiment_quote');
        });
    }
};
