<?php

namespace App\Enums;

/**
 * Tenant status (docs/spec.md 6.1).
 */
enum TenantStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Closed = 'closed';
}
