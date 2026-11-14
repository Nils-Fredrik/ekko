<?php

namespace App\Tenancy;

use LogicException;

/**
 * Thrown when tenant-owned data is read or written without a tenant in the context.
 */
final class MissingTenantContextException extends LogicException
{
    public function __construct()
    {
        parent::__construct('No tenant is set in the tenant context.');
    }
}
