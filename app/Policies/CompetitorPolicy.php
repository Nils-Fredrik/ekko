<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Competitors: tenant admin and editor (docs/spec.md 5.2).
 */
class CompetitorPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageCompetitors;
}
