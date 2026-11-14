<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Query builder for tenant-owned models (docs/spec.md 5.3, layer 1).
 *
 * Builder writes do not fire model events, so BelongsToTenant's create/update guards do not run for
 * them. This builder applies the same rules to every builder write:
 * - every write requires a tenant in the context
 * - tenant_id can never be changed
 * - inserted rows get tenant_id from the context, and rows for another tenant are refused
 * - writes the tenant scope cannot cover (updateFrom, insertUsing, truncate) are refused
 * - forceDelete, which Eloquent runs without scopes, always applies the tenant
 *
 * Methods Eloquent forwards to the base query builder without scopes (updateOrInsert, incrementEach,
 * decrementEach) are routed through toBase(), which applies the tenant scope.
 *
 * Raw queries (DB::table, toBase()) bypass this layer. Row-level security (T04) covers them.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class TenantBuilder extends Builder
{
    /**
     * @param  array<array-key, mixed>  $values
     */
    public function update(array $values): int
    {
        $this->ensureTenantUnchanged($values);

        return parent::update($values);
    }

    /**
     * The conflict target must include tenant_id. Every inserted row gets the context tenant, so a
     * conflict can then only hit a row in the same tenant. Without it, ON CONFLICT … DO UPDATE could
     * update another tenant's row, because the tenant scope does not apply to the conflict branch.
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<array-key, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        if ($values === []) {
            return 0;
        }

        $uniqueBy = (array) $uniqueBy;
        if (! array_filter($uniqueBy, fn (string $column) => $this->isTenantColumn($column))) {
            throw new LogicException('upsert on a tenant-owned model must include tenant_id in the conflict target ($uniqueBy).');
        }

        $rows = $this->withContextTenant($values);

        // The default update list is the given columns, never the tenant_id this builder adds.
        $update ??= array_values(array_filter(
            array_keys($rows[0]),
            fn (string $column) => ! $this->isTenantColumn($column),
        ));
        $this->ensureTenantUnchanged(array_is_list($update) ? array_flip(array_filter($update, 'is_string')) : $update);

        return parent::upsert($rows, $uniqueBy, $update);
    }

    /**
     * @param  string|Expression  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $this->ensureTenantUnchanged([(string) (is_string($column) ? $column : '') => true, ...$extra]);

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  string|Expression  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $this->ensureTenantUnchanged([(string) (is_string($column) ? $column : '') => true, ...$extra]);

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        $this->ensureTenantUnchanged([...$columns, ...$extra]);

        return $this->toBase()->incrementEach($columns, $this->addUpdatedAtColumn($extra));
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        $this->ensureTenantUnchanged([...$columns, ...$extra]);

        return $this->toBase()->decrementEach($columns, $this->addUpdatedAtColumn($extra));
    }

    /**
     * Eloquent's forceDelete() deletes through the base query without any scope. Here it keeps every
     * other scope off (so soft-deleted rows can be removed for good), but always applies the tenant.
     */
    public function forceDelete(): int
    {
        $query = $this->getQuery()->clone();
        $query->where($this->model->qualifyColumn('tenant_id'), current_tenant_id());

        return $query->delete();
    }

    /**
     * Truncating would empty the table for every tenant.
     */
    public function truncate(): never
    {
        throw new LogicException('truncate is not supported for tenant-owned models.');
    }

    /**
     * @param  array<int, string>|string|null  $column
     */
    public function touch($column = null): int|false
    {
        if ($column !== null) {
            $this->ensureTenantUnchanged(array_flip((array) $column));
        }

        return parent::touch($column);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): bool
    {
        return $this->toBase()->insert($this->withContextTenant($values));
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        return $this->toBase()->insertOrIgnore($this->withContextTenant($values));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  string|null  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int
    {
        return (int) $this->toBase()->insertGetId($this->withContextTenant($values)[0], $sequence);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|callable  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        if (is_callable($values)) {
            throw new LogicException('updateOrInsert with a callback is not supported for tenant-owned models.');
        }

        $this->ensureTenantUnchanged($values);
        $attributes = $this->withContextTenant($attributes)[0];

        return $this->toBase()->updateOrInsert($attributes, $values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function updateFrom(array $values): never
    {
        throw new LogicException('updateFrom is not supported for tenant-owned models.');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  Closure|\Illuminate\Database\Query\Builder|Builder<Model>|string  $query
     */
    public function insertUsing(array $columns, $query): never
    {
        throw new LogicException('insertUsing is not supported for tenant-owned models.');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  Closure|\Illuminate\Database\Query\Builder|Builder<Model>|string  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): never
    {
        throw new LogicException('insertOrIgnoreUsing is not supported for tenant-owned models.');
    }

    /**
     * Refuses any write that would set tenant_id, also as a qualified column (table.tenant_id).
     *
     * @param  array<array-key, mixed>  $values
     */
    protected function ensureTenantUnchanged(array $values): void
    {
        current_tenant_id();

        foreach (array_keys($values) as $column) {
            if (is_string($column) && $this->isTenantColumn($column)) {
                throw TenantMismatchException::tenantChanged($this->model::class);
            }
        }
    }

    /**
     * Sets tenant_id from the context on every row, and refuses rows for another tenant.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<array<string, mixed>>
     */
    protected function withContextTenant(array $values): array
    {
        $tenantId = current_tenant_id();
        $rows = array_is_list($values) && is_array($values[0] ?? null) ? $values : [$values];

        return array_map(function (array $row) use ($tenantId): array {
            foreach ($row as $column => $value) {
                if (is_string($column) && $this->isTenantColumn($column) && $column !== 'tenant_id') {
                    unset($row[$column]);
                    $row['tenant_id'] = $value;
                }
            }

            if (array_key_exists('tenant_id', $row) && $row['tenant_id'] !== null && (int) $row['tenant_id'] !== $tenantId) {
                throw TenantMismatchException::forModel($this->model::class, (int) $row['tenant_id'], $tenantId);
            }

            $row['tenant_id'] = $tenantId;

            return $row;
        }, $rows);
    }

    protected function isTenantColumn(string $column): bool
    {
        $column = strtolower(trim(str_replace(['"', '`'], '', $column)));

        return $column === 'tenant_id' || str_ends_with($column, '.tenant_id');
    }
}
