<?php

namespace App\Tenancy;

/**
 * The tenant the current request or job works in (docs/spec.md 5.3, layer 1).
 *
 * Registered as a scoped instance, so it is reset between requests and between queued jobs.
 * Read it through current_tenant_id(), which throws when no tenant is set.
 */
final class TenantContext
{
    private ?int $tenantId = null;

    public function set(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function forget(): void
    {
        $this->tenantId = null;
    }

    public function check(): bool
    {
        return $this->tenantId !== null;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    /**
     * @throws MissingTenantContextException
     */
    public function require(): int
    {
        return $this->tenantId ?? throw new MissingTenantContextException;
    }

    /**
     * Runs the callback in the given tenant and restores the previous context afterwards,
     * also when the callback throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(int $tenantId, callable $callback): mixed
    {
        $previous = $this->tenantId;
        $this->tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }
}
