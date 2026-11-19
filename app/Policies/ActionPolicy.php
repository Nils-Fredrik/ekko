<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Actions (docs/spec.md 11.3): every role sees them; the roles that edit prompts and content (tenant admin,
 * partner advisor, editor) change their status, tick off steps and create their own (ADR 0034).
 */
class ActionPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManagePrompts;
}
