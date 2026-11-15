<?php

namespace App\Mcp;

use App\Models\McpAuthCode;
use App\Models\McpClient;
use App\Models\McpConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The tokens of the Claude connection (OAuth 2.1 with PKCE, public clients, ADR 0016). The access token is a
 * Sanctum token with the ability "mcp" (and "mcp:write" when the user allowed changes) that lasts one hour and
 * only works on /mcp: the REST API needs "read" or "write". The refresh token is rotated on every use, as
 * public clients require, and lasts 30 days after its last use. Disconnecting deletes both at once.
 */
final class McpTokens
{
    public const READ = 'mcp';

    public const WRITE = 'mcp:write';

    /** OAuth scopes and the abilities they give. */
    public const SCOPE_READ = 'ekko:read';

    public const SCOPE_WRITE = 'ekko:write';

    public const TOKEN_NAME = 'Claude (MCP)';

    public const ACCESS_MINUTES = 60;

    public const REFRESH_DAYS = 30;

    public const CODE_MINUTES = 5;

    /**
     * The one-time code after consent. Returns the code in clear text; only its hash is stored.
     *
     * @param  list<string>  $abilities
     */
    public function issueCode(User $user, Team $tenant, McpClient $client, string $redirectUri, string $codeChallenge, array $abilities): string
    {
        $code = Str::random(64);
        McpAuthCode::query()->create([
            'code_hash' => self::hash($code),
            'client_id' => $client->id,
            'user_id' => $user->id,
            'team_id' => $tenant->id,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'abilities' => $abilities,
            'expires_at' => Date::now()->addMinutes(self::CODE_MINUTES),
        ]);

        return $code;
    }

    /**
     * Exchanges the code (authorization_code). Null when the code, client, callback or PKCE verifier does not
     * match. A code used twice disconnects the connection it gave (RFC 6749 4.1.2).
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}|null
     */
    public function exchangeCode(string $code, string $clientId, string $redirectUri, string $verifier): ?array
    {
        return DB::transaction(function () use ($code, $clientId, $redirectUri, $verifier): ?array {
            $row = McpAuthCode::query()->where('code_hash', self::hash($code))->lockForUpdate()->first();
            if ($row === null || $row->client_id !== $clientId || $row->redirect_uri !== $redirectUri) {
                return null;
            }
            if ($row->used_at !== null) {
                McpConnection::query()->where('user_id', $row->user_id)->where('client_id', $row->client_id)
                    ->where('created_at', '>=', $row->used_at)->get()->each(fn (McpConnection $c) => $this->revoke($c));

                return null;
            }
            $row->update(['used_at' => Date::now()]);
            if ($row->expires_at->isPast() || ! self::verifies($verifier, $row->code_challenge)) {
                return null;
            }
            $user = $row->user;
            $client = $row->client;
            if ($user === null || $client === null) {
                return null;
            }

            $connection = McpConnection::query()->create([
                'user_id' => $user->id,
                'team_id' => $row->team_id,
                'client_id' => $client->id,
                'client_name' => Str::limit($client->name, 200, ''),
                'redirect_host' => Str::limit(McpRedirects::host($row->redirect_uri), 200, ''),
                'abilities' => $row->abilities,
                'last_used_at' => Date::now(),
            ]);

            return $this->rotate($connection, $user);
        });
    }

    /**
     * Renews with the refresh token: a new access token and a new refresh token; the old one stops working.
     * Null when the token is unknown, expired, disconnected or belongs to another client.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}|null
     */
    public function refresh(string $refreshToken, string $clientId): ?array
    {
        return DB::transaction(function () use ($refreshToken, $clientId): ?array {
            $connection = McpConnection::query()->active()->where('refresh_token_hash', self::hash($refreshToken))->lockForUpdate()->first();
            if ($connection === null || $connection->client_id !== $clientId || $connection->refresh_expires_at?->isPast()) {
                return null;
            }
            $user = $connection->user;

            return $user === null ? null : $this->rotate($connection, $user);
        });
    }

    /** Disconnects: the access token is deleted and the refresh token forgotten. */
    public function revoke(McpConnection $connection): void
    {
        if ($connection->access_token_id !== null) {
            PersonalAccessToken::query()->whereKey($connection->access_token_id)->delete();
        }
        $connection->forceFill([
            'revoked_at' => $connection->revoked_at ?? Date::now(),
            'refresh_token_hash' => null,
            'access_token_id' => null,
        ])->save();
    }

    /** The active connection an access token belongs to (null for ordinary API tokens). */
    public function connectionFor(?PersonalAccessToken $token): ?McpConnection
    {
        if ($token === null || ! $token->can(self::READ)) {
            return null;
        }

        return McpConnection::query()->active()->where('access_token_id', $token->getKey())->first();
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     */
    private function rotate(McpConnection $connection, User $user): array
    {
        if ($connection->access_token_id !== null) {
            PersonalAccessToken::query()->whereKey($connection->access_token_id)->delete();
        }
        $abilities = $connection->abilities ?: [self::READ];
        $access = $user->createToken(self::TOKEN_NAME, $abilities, Date::now()->addMinutes(self::ACCESS_MINUTES));
        $refresh = Str::random(64);
        $connection->forceFill([
            'access_token_id' => $access->accessToken->getKey(),
            'refresh_token_hash' => self::hash($refresh),
            'refresh_expires_at' => Date::now()->addDays(self::REFRESH_DAYS),
        ])->save();

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh,
            'expires_in' => self::ACCESS_MINUTES * 60,
            'scope' => self::scopeFor($abilities),
        ];
    }

    /** @param  list<string>  $abilities */
    public static function scopeFor(array $abilities): string
    {
        return in_array(self::WRITE, $abilities, true) ? self::SCOPE_READ.' '.self::SCOPE_WRITE : self::SCOPE_READ;
    }

    /** PKCE S256: base64url(sha256(verifier)) without padding. */
    public static function verifies(string $verifier, string $challenge): bool
    {
        if (preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) !== 1) {
            return false;
        }

        return hash_equals($challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
