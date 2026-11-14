<?php

namespace App\Tenancy;

use LogicException;

/**
 * Thrown when a model would be written to another tenant than the one in the context.
 */
final class TenantMismatchException extends LogicException
{
    public static function forModel(string $model, int $modelTenantId, int $contextTenantId): self
    {
        return new self("{$model} belongs to tenant {$modelTenantId}, but the context is tenant {$contextTenantId}.");
    }

    public static function tenantChanged(string $model): self
    {
        return new self("The tenant of {$model} cannot be changed.");
    }
}
