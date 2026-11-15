<?php

namespace App\Models;

use App\Mcp\McpTokens;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Claude connection of a user (docs/spec.md 15.2): the tenant chosen on the consent page, whether
 * Claude may also make changes, the current access token and the hash of the refresh token.
 *
 * @property int $id
 * @property int $user_id
 * @property int $team_id
 * @property string $client_id
 * @property string $client_name
 * @property string $redirect_host
 * @property list<string> $abilities
 * @property int|null $access_token_id
 * @property string|null $refresh_token_hash
 * @property Carbon|null $refresh_expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
class McpConnection extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id', 'team_id', 'client_id', 'client_name', 'redirect_host', 'abilities',
        'access_token_id', 'refresh_token_hash', 'refresh_expires_at', 'last_used_at', 'revoked_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @param  Builder<McpConnection>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function canWrite(): bool
    {
        return in_array(McpTokens::WRITE, $this->abilities, true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'refresh_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
