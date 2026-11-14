<?php

namespace App\Tenancy;

use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

/**
 * "Act as tenant" for platform admins (docs/spec.md 5.2 and 16): the admin sees a tenant as the
 * tenant sees itself. The state lives in the session, never on the user, so it ends with the
 * session. Starting and stopping is logged by ActingAsController, and a banner shows it.
 */
final class ActingAs
{
    private const KEY = 'acting_as';

    public function __construct(private readonly Session $session) {}

    public function start(User $admin, Team $tenant, string $reason): void
    {
        $this->session->put(self::KEY, [
            'admin_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'reason' => $reason,
            'started_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    public function stop(): void
    {
        $this->session->forget(self::KEY);
    }

    /**
     * The tenant the given platform admin acts as, or null. The state is ignored if it belongs to
     * another user or the user is no longer a platform admin.
     */
    public function tenantIdFor(User $user): ?int
    {
        $state = $this->state();

        if ($state === null || $state['admin_id'] !== $user->id || ! $user->isPlatformAdmin()) {
            return null;
        }

        return $state['tenant_id'];
    }

    /**
     * @return array{admin_id: int, tenant_id: int, reason: string, started_at: string}|null
     */
    public function state(): ?array
    {
        $state = $this->session->get(self::KEY);

        if (! is_array($state) || ! is_int($state['admin_id'] ?? null) || ! is_int($state['tenant_id'] ?? null)) {
            return null;
        }

        return [
            'admin_id' => $state['admin_id'],
            'tenant_id' => $state['tenant_id'],
            'reason' => (string) ($state['reason'] ?? ''),
            'started_at' => (string) ($state['started_at'] ?? ''),
        ];
    }
}
