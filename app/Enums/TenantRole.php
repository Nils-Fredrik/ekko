<?php

namespace App\Enums;

/**
 * The Jetstream roles per tenant (docs/spec.md 5.2). The team owner counts as Admin.
 *
 * PartnerAdvisor is a role in a partner tenant. It gives tenant-admin rights in the partner tenant
 * and in all its client tenants (teams.partner_team_id). Deleting tenants and changing the plan is
 * reserved for the platform admin, so a partner advisor can do neither in a client tenant.
 */
enum TenantRole: string
{
    case Admin = 'admin';
    case PartnerAdvisor = 'partner_advisor';
    case Editor = 'editor';
    case Reader = 'reader';

    /**
     * @return list<TenantPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin, self::PartnerAdvisor => TenantPermission::cases(),
            self::Editor => [
                TenantPermission::ViewData,
                TenantPermission::ManagePrompts,
                TenantPermission::ManageCompetitors,
                TenantPermission::RunChecks,
            ],
            self::Reader => [
                TenantPermission::ViewData,
            ],
        };
    }

    public function grants(TenantPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Translation keys in lang/{locale}/roles.php, translated in the frontend.
     */
    public function nameKey(): string
    {
        return "roles.{$this->value}.name";
    }

    public function descriptionKey(): string
    {
        return "roles.{$this->value}.description";
    }
}
