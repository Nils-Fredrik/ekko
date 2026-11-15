<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Competitor domains are part of the competitor: tenant admin and editor (docs/spec.md 5.2).
 */
class CompetitorDomainPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageCompetitors;
}
