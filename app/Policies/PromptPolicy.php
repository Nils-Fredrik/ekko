<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Prompts: tenant admin and editor (docs/spec.md 5.2).
 */
class PromptPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManagePrompts;
}
