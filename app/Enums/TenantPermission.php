<?php

namespace App\Enums;

/**
 * What a role may do in a tenant (docs/spec.md 5.2). Platform admin rights (create, close and
 * delete tenants, set plan and engines) are not tenant permissions: they follow from
 * users.is_platform_admin and are checked in the policies.
 */
enum TenantPermission: string
{
    /** Dashboard, answers, sources and reports. */
    case ViewData = 'tenant.view';
    case ManagePrompts = 'prompts.manage';
    case ManageCompetitors = 'competitors.manage';
    /** Manual checks within the quota. */
    case RunChecks = 'checks.run';
    case ManageBrands = 'brands.manage';
    case ManageMarkets = 'markets.manage';
    case ManageAlerts = 'alerts.manage';
    case ManageReports = 'reports.manage';
    case ViewUsage = 'usage.view';
    /** Invite and remove users, change their roles. */
    case ManageMembers = 'members.manage';
    /** Tenant name and settings: Slack webhook, report profile, own API keys. */
    case UpdateTenant = 'tenant.update';
}
