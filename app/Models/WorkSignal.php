<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Work\WorkKind;
use App\Work\WorkSignals;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable processing work of one tenant, kind and scope (docs/spec.md 7.1, ADR 0032). Written only through
 * {@see WorkSignals}; the model is for reading. Pending while version > processed_version.
 *
 * @property int $id
 * @property int $tenant_id
 * @property WorkKind $kind
 * @property string $scope
 * @property int $version
 * @property int $processed_version
 * @property CarbonInterface $available_at
 * @property CarbonInterface|null $pending_since
 * @property CarbonInterface $last_signaled_at
 * @property string|null $lease_token
 * @property int|null $lease_version
 * @property CarbonInterface|null $leased_at
 * @property CarbonInterface|null $lease_expires_at
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonInterface|null $processed_at
 */
class WorkSignal extends Model
{
    use BelongsToTenant;

    /** Every column is set by the service's SQL, never by mass assignment. */
    protected $guarded = ['*'];

    public function isPending(): bool
    {
        return $this->version > $this->processed_version;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => WorkKind::class,
            'version' => 'integer',
            'processed_version' => 'integer',
            'lease_version' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'pending_since' => 'immutable_datetime',
            'last_signaled_at' => 'immutable_datetime',
            'leased_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
