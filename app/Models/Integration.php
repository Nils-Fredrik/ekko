<?php

namespace App\Models;

use App\Casts\EncryptedIntegrationConfig;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsRevisions;
use App\Workspace\WorkspaceCounts;
use Carbon\CarbonImmutable;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $tenant_id
 * @property array<string, mixed> $config
 * @property Carbon|null $last_sync_at
 * @property Carbon|null $last_error_at
 * @property Carbon|null $last_failed_on
 */
class Integration extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    use RecordsRevisions;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'type',
        'config',
        'last_sync_at',
        'status',
    ];

    /** @var list<string> */
    protected $hidden = [
        'config',
    ];

    /** Update operational state directly so that a failed sync never needs to decrypt its config again. */
    public function recordSync(?CarbonImmutable $failedDay = null): void
    {
        $status = $failedDay === null ? 'synced' : 'error';
        self::whereKey($this->id)->update([
            'status' => $status,
            'last_failed_on' => $failedDay?->toDateString(),
            ($failedDay === null ? 'last_sync_at' : 'last_error_at') => now(),
        ]);
        if ($this->status !== $status) {
            DB::afterCommit(fn () => WorkspaceCounts::flush($this->tenant_id));
        }
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'config' => EncryptedIntegrationConfig::class,
            'last_sync_at' => 'datetime',
            'last_failed_on' => 'date',
            'last_error_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    protected function revisionAttributes(): array
    {
        return ['brand_id', 'type'];
    }

    /** @return list<string> */
    protected function maskedRevisionAttributes(): array
    {
        return ['config'];
    }
}
