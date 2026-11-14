<?php

namespace Tests\Fixtures;

use App\Enums\TenantPermission;
use App\Policies\TenantResourcePolicy;

class TenantRecordPolicy extends TenantResourcePolicy
{
    protected TenantPermission $manage = TenantPermission::ManagePrompts;
}
