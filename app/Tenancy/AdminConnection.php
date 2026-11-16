<?php

namespace App\Tenancy;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only way into the pgsql_admin connection, a role with BYPASSRLS (docs/spec.md 5.3, T39-F2).
 * Only the signed-in user can use it, and only as a platform admin; the actor is taken from the session,
 * never passed in. Every use, and every refused attempt, is logged with who and why. tests/Feature/TenantIsolation/CodebaseGuardsTest.php fails if any other file names the
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
    public function run(string $purpose, Closure $callback): mixed
    {
        $admin = Auth::user();

        if (! $admin instanceof User || ! $admin->isPlatformAdmin()) {
            Log::warning('admin_connection.denied', ['user_id' => $admin?->getAuthIdentifier(), 'purpose' => $purpose]);

            throw new AuthorizationException('Only a signed-in platform admin can use the admin connection.');
        }

        Log::notice('admin_connection.used', ['user_id' => $admin->id, 'purpose' => $purpose]);

        return $callback(DB::connection(self::CONNECTION));
    }
}
