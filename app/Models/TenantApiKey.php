<?php

namespace App\Models;

use App\Engines\Credentials\ApiKeyProvider;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsRevisions;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property ApiKeyProvider $provider
 * @property string $key
 * @property CarbonInterface|null $last_verified_at
 */
class TenantApiKey extends Model
{
    use BelongsToTenant;
    use RecordsRevisions;

    /** @var list<string> */
    protected $fillable = ['provider', 'key', 'last_verified_at'];

    /** @var list<string> */
    protected $hidden = ['key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => ApiKeyProvider::class,
            'key' => 'encrypted',
            'last_verified_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    protected function revisionAttributes(): array
    {
        return ['provider', 'last_verified_at'];
    }

    /** @return list<string> */
    protected function maskedRevisionAttributes(): array
    {
        return ['key'];
    }
}
