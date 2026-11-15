<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Claude connection (MCP, docs/spec.md 15.2, ADR 0016): clients Claude registers itself, one-time
 * authorization codes and the connections with their refresh tokens. These belong to a user, like API
 * tokens, not to a tenant: team_id is the tenant the user chose on the consent page, and the tools
 * check access to it on every call. Only hashes of codes and refresh tokens are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_clients', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('name', 200);
            $table->jsonb('redirect_uris');
            $table->timestampsTz();
            $table->index('created_at');
        });

        Schema::create('mcp_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('client_id', 80);
            $table->string('client_name', 200);
            $table->string('redirect_host', 200);
            $table->jsonb('abilities');
            $table->foreignId('access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->char('refresh_token_hash', 64)->nullable()->unique();
            // Hashes of refresh tokens already used: a replay of one disconnects the connection (RFC 9700 4.14.2).
            $table->jsonb('used_refresh_hashes')->default('[]');
            $table->timestampTz('refresh_expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->foreign('client_id')->references('id')->on('mcp_clients')->cascadeOnDelete();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('mcp_auth_codes', function (Blueprint $table): void {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->string('client_id', 80);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->text('redirect_uri');
            $table->string('code_challenge', 128);
            $table->jsonb('abilities');
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            // The connection the code gave, so a reused code disconnects only that one.
            $table->foreignId('mcp_connection_id')->nullable()->constrained('mcp_connections')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign('client_id')->references('id')->on('mcp_clients')->cascadeOnDelete();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_auth_codes');
        Schema::dropIfExists('mcp_connections');
        Schema::dropIfExists('mcp_clients');
    }
};
