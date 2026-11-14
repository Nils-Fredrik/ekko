<?php

namespace App\Models\Concerns;

use App\Models\Team;
use App\Tenancy\TenantBuilder;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMismatchException;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every tenant-owned model (docs/spec.md 5.3, layer 1):
 * - queries are limited to the tenant in the context (TenantScope)
 * - tenant_id is set from the context on create
 * - reading, creating, updating or deleting without a tenant in the context throws
 * - writing to another tenant than the one in the context throws, and tenant_id can never change
 * - the same rules apply to builder writes (update, insert, upsert …) through TenantBuilder
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    /**
     * The Eloquent builder for tenant-owned models (guards builder writes).
     *
     * @var class-string<TenantBuilder<Model>>
     */
    protected static string $builder = TenantBuilder::class;

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $contextTenantId = app(TenantContext::class)->require();
            $tenantId = $model->getAttribute('tenant_id');

            if ($tenantId === null) {
                $model->setAttribute('tenant_id', $contextTenantId);

                return;
            }

            if ((int) $tenantId !== $contextTenantId) {
                throw TenantMismatchException::forModel($model::class, (int) $tenantId, $contextTenantId);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw TenantMismatchException::tenantChanged($model::class);
            }

            static::ensureModelInContextTenant($model);
        });

        static::deleting(function (Model $model): void {
            static::ensureModelInContextTenant($model);
        });
    }

    /**
     * A model loaded in one tenant must not be saved or deleted while the context is another tenant.
     */
    protected static function ensureModelInContextTenant(Model $model): void
    {
        $contextTenantId = app(TenantContext::class)->require();
        $tenantId = (int) $model->getOriginal('tenant_id');

        if ($tenantId !== $contextTenantId) {
            throw TenantMismatchException::forModel($model::class, $tenantId, $contextTenantId);
        }
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'tenant_id');
    }
}
