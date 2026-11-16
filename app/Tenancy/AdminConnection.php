<?php

namespace App\Tenancy;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only way into the pgsql_admin connection, a role with BYPASSRLS (docs/spec.md 5.3, T39-F2).
 * Only a platform admin can use it, and every use is logged with who and why before the callback
 * runs. tests/Feature/TenantIsolation/CodebaseGuardsTest.php fails if any other file names the
 * connection. The entry goes to the application log: a use across tenants has no tenant revision
 * log to write to.
 */
final class AdminConnection
{
    private const CONNECTION = 'pgsql_admin';

    /**
     * @template TResult
     *
     * @param  Closure(ConnectionInterface): TResult  $callback
     * @return TResult
     *
     * @throws AuthorizationException
     */
    public function run(User $admin, string $purpose, Closure $callback): mixed
    {
        if (! $admin->isPlatformAdmin()) {
            throw new AuthorizationException('Only a platform admin can use the admin connection.');
        }

        Log::notice('admin_connection.used', ['user_id' => $admin->id, 'purpose' => $purpose]);

        return $callback(DB::connection(self::CONNECTION));
    }
}
