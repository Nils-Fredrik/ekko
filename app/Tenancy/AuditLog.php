<?php

namespace App\Tenancy;

use App\Models\Team;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

    public function memberAdded(?User $by, Team $tenant, User $member, ?string $role): void
    {
        $this->record($tenant, 'member.added', $by, $member, ['role' => $role]);
    }

    public function memberRoleChanged(User $by, Team $tenant, User $member, string $from, string $to): void
    {
        $this->record($tenant, 'member.role_changed', $by, $member, ['from' => $from, 'to' => $to]);
    }

    public function memberRemoved(User $by, Team $tenant, User $member, string $role): void
    {
        $this->record($tenant, 'member.removed', $by, $member, ['role' => $role]);
    }

    /** The e-mail address is kept out of the entry; the invitation row has it while it exists (T39-F6). */
    public function invitationSent(User $by, Team $tenant, int $invitationId, ?string $role): void
    {
        $this->record($tenant, 'invitation.sent', $by, null, ['invitation_id' => $invitationId, 'role' => $role]);
    }

    public function invitationCancelled(User $by, Team $tenant, int $invitationId, ?string $role): void
    {
        $this->record($tenant, 'invitation.cancelled', $by, null, ['invitation_id' => $invitationId, 'role' => $role]);
    }

    public function tenantCreated(User $by, Team $tenant): void
    {
        $this->record($tenant, 'tenant.created', $by, null, ['name' => $tenant->name]);
    }

    public function pitchConverted(User $by, Team $tenant, ?int $previousPlanId): void
    {
        $this->record($tenant, 'pitch.converted', $by, null, [
            'from' => ['kind' => 'pitch', 'plan_id' => $previousPlanId],
            'to' => ['kind' => 'customer', 'plan_id' => $tenant->plan_id],
            'partner_team_id' => $tenant->partner_team_id,
        ]);
    }

    public function tenantRenamed(User $by, Team $tenant, string $from): void
    {
        $this->record($tenant, 'tenant.renamed', $by, null, ['from' => $from, 'to' => $tenant->name]);
    }

    /**
     * @param  array{plan_id: int|null, partner_team_id: int|null, status: string, monthly_call_quota_override: int|null}  $from
     */
    public function tenantSettingsChanged(User $by, Team $tenant, array $from): void
    {
        $this->record($tenant, 'tenant.settings_changed', $by, null, [
            'from' => $from,
            'to' => [
                'plan_id' => $tenant->plan_id,
                'partner_team_id' => $tenant->partner_team_id,
                'status' => $tenant->status->value,
                'monthly_call_quota_override' => $tenant->monthly_call_quota_override,
            ],
        ]);
    }

    /** Closing starts the 30 days before the tenant and its data are deleted (docs/spec.md 16). */
    public function tenantClosed(User $by, Team $tenant): void
    {
        $this->record($tenant, 'tenant.closed', $by, null, ['purge_after' => $tenant->closed_at?->addDays(Team::PURGE_AFTER_DAYS)->toIso8601String()]);
    }

    /**
     * @param  list<string>  $abilities  The token's permissions and tenant binding, never the token itself.
     */
    public function apiTokenCreated(User $by, Team $tenant, int $tokenId, string $name, array $abilities): void
    {
        $this->record($tenant, 'api_token.created', $by, null, ['token_id' => $tokenId, 'name' => $name, 'abilities' => $abilities]);
    }

    /**
     * @param  list<string>  $from
     * @param  list<string>  $to
     */
    public function apiTokenUpdated(User $by, Team $tenant, int $tokenId, array $from, array $to): void
    {
        $this->record($tenant, 'api_token.updated', $by, null, ['token_id' => $tokenId, 'from' => $from, 'to' => $to]);
    }

    public function apiTokenDeleted(User $by, Team $tenant, int $tokenId, string $name): void
    {
        $this->record($tenant, 'api_token.deleted', $by, null, ['token_id' => $tokenId, 'name' => $name]);
    }

    public function billingMarkupChanged(User $by, Team $tenant, ?string $previous): void
    {
        $this->record($tenant, 'billing.markup_changed', $by, null, ['from' => $previous, 'to' => $tenant->billing_markup_percent]);
    }

    public function byokBudgetChanged(User $by, Team $tenant, ?string $previous): void
    {
        $this->record($tenant, 'byok.budget_changed', $by, null, [
            'from' => $previous,
            'to' => $tenant->monthly_byok_budget_usd,
        ]);
    }

    /**
     * The Slack webhook was set or cleared. The address itself is a secret and is not recorded.
     */
    public function slackWebhookChanged(User $by, Team $tenant): void
    {
        $this->record($tenant, 'slack.webhook_changed', $by, null, []);
    }

    public function reportBrandingChanged(User $by, Team $tenant): void
    {
        $primary = $tenant->branding['primary'] ?? null;

        $this->record($tenant, 'reports.branding_changed', $by, null, [
            'primary' => is_string($primary) ? $primary : null,
        ]);
    }

    /**
     * A change made through the Claude connection (MCP, docs/spec.md 15.2). The changed rows also have their own
     * revisions (RecordsRevisions); this entry says that Claude made them, with which tool and connection.
     *
     * @param  array<string, mixed>  $properties  Counts and ids only, never texts.
     */
    public function mcpChange(User $by, Team $tenant, string $tool, array $properties): void
    {
        $this->record($tenant, "mcp.{$tool}", $by, null, ['source' => 'mcp', 'tool' => $tool, ...$properties]);
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

        // Only once the entry is committed, so the application log never shows an event that was
        // rolled back with the request (ApplyTenantRls rolls back on a 5xx). The properties come
        // first, so none of them can overwrite the ids.
        $context = [
            ...$properties,
            'user_id' => $causer?->id,
            'tenant_id' => $tenant->id,
            'subject_user_id' => $subject?->getKey(),
        ];
        DB::afterCommit(fn () => Log::info($event, $context));
    }
}
