<?php

namespace Tests\Feature\TenantIsolation\Support;

use Closure;

/**
 * How a route may be reached (docs/spec.md 5.3 and T06). Every registered route must have an entry
 * in tests/Feature/TenantIsolation/routes.php; a route without one fails RouteCoverageTest.
 *
 * - public: no login (health check, CSRF cookie, the root redirect)
 * - guest: login pages for guests only
 * - user: signed in, the user's own data only (profile, API tokens), no tenant data
 * - tenant: tenant data; $foreign builds a request against tenant B, which a user of tenant A
 *   must not reach, and $deniedRoles are roles in the user's own tenant that must get 403
 * - platformAdmin: only users with is_platform_admin
 *
 * A route with parameters must say how to reach another tenant's or user's resource ($foreign),
 * or explain why that does not apply (withoutForeignCheck).
 */
final class RouteAccess
{
    public const PUBLIC = 'public';

    public const GUEST = 'guest';

    public const USER = 'user';

    public const TENANT = 'tenant';

    public const PLATFORM_ADMIN = 'platform_admin';

    /** Every role in a tenant, with the owner. */
    public const TENANT_ROLES = ['owner', 'admin', 'partner_advisor', 'editor', 'reader'];

    /**
     * @param  (Closure(Scenario): array{params?: array<string, mixed>, data?: array<string, mixed>})|null  $foreign
     * @param  (Closure(Scenario): array{params?: array<string, mixed>, data?: array<string, mixed>})|null  $own
     * @param  list<string>  $deniedRoles
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?Closure $foreign = null,
        public readonly ?Closure $own = null,
        public readonly array $deniedRoles = [],
        public readonly ?string $noForeignCheck = null,
        public readonly bool $signed = false,
    ) {}

    /** Google-signed Scheduler OIDC only; covered by Scheduling/SchedulerAuthenticationTest. */
    public static function service(): self
    {
        return new self('service');
    }

    public static function public(): self
    {
        return new self(self::PUBLIC);
    }

    public static function guest(): self
    {
        return new self(self::GUEST);
    }

    public static function user(?Closure $foreign = null, ?Closure $own = null): self
    {
        return new self(self::USER, foreign: $foreign, own: $own);
    }

    /**
     * @param  list<string>  $deniedRoles
     */
    public static function tenant(Closure $foreign, ?Closure $own = null, array $deniedRoles = []): self
    {
        return new self(self::TENANT, foreign: $foreign, own: $own, deniedRoles: $deniedRoles);
    }

    public static function platformAdmin(?Closure $own = null): self
    {
        return new self(self::PLATFORM_ADMIN, own: $own);
    }

    public function signed(): self
    {
        return new self($this->kind, $this->foreign, $this->own, $this->deniedRoles, $this->noForeignCheck, true);
    }

    public function withoutForeignCheck(string $reason): self
    {
        return new self($this->kind, $this->foreign, $this->own, $this->deniedRoles, $reason, $this->signed);
    }

    public function requiresLogin(): bool
    {
        return in_array($this->kind, [self::USER, self::TENANT, self::PLATFORM_ADMIN], true);
    }
}
