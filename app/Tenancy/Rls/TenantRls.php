<?php

namespace App\Tenancy\Rls;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Throwable;

/**
 * Row-level security, layer 2 of tenant isolation (docs/spec.md 5.3).
 *
 * run() executes a callback in a database transaction with
 *   SET LOCAL ROLE <rls role>                  (no superuser, no BYPASSRLS)
 *   set_config('app.tenant_ids', '1,2', true)  (the tenants the request or job may see)
 * so the tenant_isolation policies apply to every query in it, also raw queries and queries
 * without the Eloquent tenant scope. With no tenants, no tenant-owned rows are visible.
 *
 * Both settings are transaction-local. In a nested call (a job dispatched synchronously in a
 * request, or tests in a RefreshDatabase transaction) the previous role and tenants are restored
 * afterwards, because SET LOCAL survives a released savepoint.
 */
final class TenantRls
{
    public function __construct(private readonly DatabaseManager $db) {}

    public static function roleName(): string
    {
        $role = config('database.rls.role');

        if (! is_string($role) || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new InvalidArgumentException('database.rls.role must be a lower-case PostgreSQL identifier.');
        }

        return $role;
    }

    /**
     * @template TResult
     *
     * @param  array<int, int>  $tenantIds
     * @param  callable(): TResult  $callback
     * @param  (Closure(TResult): bool)|null  $commitIf  Roll back instead of committing when this returns false.
     * @return TResult
     */
    public function run(array $tenantIds, callable $callback, ?Closure $commitIf = null, ?string $connection = null): mixed
    {
        $db = $this->db->connection($connection);
        $previous = $db->transactionLevel() > 0 ? $this->currentSettings($db) : null;

        $db->beginTransaction();

        try {
            $db->statement('SET LOCAL ROLE '.$this->quoteIdentifier(self::roleName()));
            $db->select("SELECT set_config('app.tenant_ids', ?, true)", [$this->formatTenantIds($tenantIds)]);

            $result = $callback();

            if ($commitIf !== null && ! $commitIf($result)) {
                $db->rollBack();
            } else {
                $db->commit();
            }

            return $result;
        } catch (Throwable $e) {
            $db->rollBack();

            throw $e;
        } finally {
            if ($previous !== null) {
                $this->restore($db, $previous);
            }
        }
    }

    /**
     * The role and tenants the current transaction runs with.
     *
     * @return array{role: string, tenant_ids: string}
     */
    public function currentSettings(?ConnectionInterface $db = null): array
    {
        $db ??= $this->db->connection();
        $row = $db->selectOne("SELECT current_user AS role, coalesce(current_setting('app.tenant_ids', true), '') AS tenant_ids");

        return ['role' => (string) $row->role, 'tenant_ids' => (string) $row->tenant_ids];
    }

    /**
     * @param  array{role: string, tenant_ids: string}  $previous
     */
    private function restore(ConnectionInterface $db, array $previous): void
    {
        $db->statement('SET LOCAL ROLE '.$this->quoteIdentifier($previous['role']));
        $db->select("SELECT set_config('app.tenant_ids', ?, true)", [$previous['tenant_ids']]);
    }

    /**
     * Validated at runtime as well: the ids end up in the policy condition.
     *
     * @param  array<int, mixed>  $tenantIds
     */
    private function formatTenantIds(array $tenantIds): string
    {
        $ids = [];
        foreach ($tenantIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Tenant ids must be positive integers.');
            }
            $ids[$id] = $id;
        }

        return implode(',', array_values($ids));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
