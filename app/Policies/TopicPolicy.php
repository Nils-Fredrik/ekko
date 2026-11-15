<?php

namespace App\Policies;

use App\Enums\TenantPermission;

/**
 * Topics group prompts and are edited with them: tenant admin and editor (docs/spec.md 5.2).
 */
class TopicPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManagePrompts;
}
