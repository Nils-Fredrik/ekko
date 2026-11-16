<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A client Claude registered itself (dynamic client registration, RFC 7591). Public: no secret, PKCE only.
 *
 * @property string $id
 * @property string $name
 * @property list<string> $redirect_uris
 */
class McpClient extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['id', 'name', 'redirect_uris'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['redirect_uris' => 'array'];
    }

    /**
     * Clients that never gave a connection: registered, but the token exchange was not completed.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnused(Builder $query): void
    {
        $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('mcp_connections')->whereColumn('mcp_connections.client_id', 'mcp_clients.id'));
    }
}
