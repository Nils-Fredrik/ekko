<?php

namespace App\Tenancy;

use App\Models\Team;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Audit entries for access-related actions (docs/spec.md 16). Each entry goes to two places:
 * - the revision log (spatie/laravel-activitylog, table activity_log, log name "audit"), which
 *   belongs to the tenant and lies under RLS
 * - the application log (Cloud Logging in production), with user_id and tenant_id
 *
 * The entry is written in the tenant it concerns, whatever tenant the request works in: a platform
 * admin starts acting as a tenant from outside it, and a partner advisor changes roles in a client
 * tenant. So the tenant context and the RLS tenants are set to that tenant for the write only.
 */
final class AuditLog
{
    public const LOG_NAME = 'audit';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantRls $rls,
    ) {}

    public function actingAsStarted(User $admin, Team $tenant, string $reason): void
    {
        $this->record($tenant, 'acting_as.started', $admin, null, ['reason' => $reason]);
    }

    public function actingAsStopped(User $admin, Team $tenant): void
    {
        $this->record($tenant, 'acting_as.stopped', $admin, null, []);
    }

    public function memberAdded(?User $by, Team $tenant, User $member, string $role): void
    {
        $this->record($tenant, 'member.added', $by, $member, ['role' => $role]);
    }

    public function memberRoleChanged(User $by, Team $tenant, User $member, ?string $from, string $to): void
    {
        $this->record($tenant, 'member.role_changed', $by, $member, ['from' => $from, 'to' => $to]);
    }

    public function memberRemoved(User $by, Team $tenant, User $member, ?string $role): void
    {
        $this->record($tenant, 'member.removed', $by, $member, ['role' => $role]);
    }

    /**
     * @param  array<string, mixed>  $properties  No secrets, answer texts or prompt texts.
     */
    private function record(Team $tenant, string $event, ?User $causer, ?Model $subject, array $properties): void
    {
        $this->context->run($tenant->id, fn () => $this->rls->run([$tenant->id], function () use ($event, $causer, $subject, $properties): void {
            $logger = activity(self::LOG_NAME)->event($event)->causedBy($causer)->withProperties($properties);

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            $logger->log($event);
        }));

        Log::info($event, [
            'user_id' => $causer?->id,
            'tenant_id' => $tenant->id,
            'subject_user_id' => $subject?->getKey(),
            ...$properties,
        ]);
    }
}
