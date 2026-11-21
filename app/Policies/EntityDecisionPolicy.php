<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Decisions on unknown brands: the roles that may change competitors (docs/ux-arbeidsflate.md 6.3).
 */
class EntityDecisionPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManageCompetitors;
}
