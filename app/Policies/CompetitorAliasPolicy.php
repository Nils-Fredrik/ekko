<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Competitor aliases are part of the competitor: tenant admin and editor (docs/spec.md 5.2).
 */
class CompetitorAliasPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageCompetitors;
}
