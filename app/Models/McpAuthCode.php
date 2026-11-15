<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time authorization code from the consent page. Only the hash is stored.
 *
 * @property int $id
 * @property string $code_hash
 * @property string $client_id
 * @property int $user_id
 * @property int $team_id
 * @property string $redirect_uri
 * @property string $code_challenge
 * @property list<string> $abilities
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int|null $mcp_connection_id
 */
class McpAuthCode extends Model
{
    /** @var list<string> */
    protected $fillable = ['code_hash', 'client_id', 'user_id', 'team_id', 'redirect_uri', 'code_challenge', 'abilities', 'expires_at', 'used_at', 'mcp_connection_id'];

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

    /** @return BelongsTo<McpClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(McpClient::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
