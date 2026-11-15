<?php

namespace App\Mcp;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Http\Middleware\AuthenticateMcp;
use App\Models\McpConnection;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantAccess;
use Illuminate\Support\Collection;

/**
 * Who Claude acts for in this request (docs/spec.md 15.2): the user, the connection (read only or also
 * changes) and the tenant chosen on the consent page. A partner advisor can name one of the partner's client
 * tenants with the tool argument "tenant". Access is checked with TenantAccess on every call, so Claude never
 * reaches more than the user does on the pages, and a lost membership takes effect at once.
 */
final class McpContext
{
    public function __construct(
        public readonly User $user,
        public readonly ?McpConnection $connection,
        private readonly TenantAccess $access,
    ) {}

    public static function current(): ?self
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }
        $connection = request()->attributes->get(AuthenticateMcp::CONNECTION);

        return new self($user, $connection instanceof McpConnection ? $connection : null, app(TenantAccess::class));
    }

    /** Whether the user allowed changes when connecting Claude. */
    public function canWrite(): bool
    {
        return $this->connection?->canWrite() ?? false;
    }

    /**
     * The tenant chosen when connecting (without a connection, as in tests through the server: the current tenant).
     */
    public function defaultTenant(): ?Team
    {
        $id = $this->connection->team_id ?? $this->user->current_team_id;

        return $id === null ? null : Team::query()->find($id);
    }

    /**
     * The tenants Claude can work in: the chosen tenant and, when the user is a partner advisor there, its client
     * tenants. Each must be accessible to the user right now.
     *
     * @return Collection<int, Team>
     */
    public function tenants(): Collection
    {
        $default = $this->defaultTenant();
        if ($default === null || ! $this->access->canAccess($this->user, $default)) {
            return collect();
        }

        return Team::query()->whereIn('id', $this->access->rlsTenantIds($this->user, $default))
            ->where('personal_team', false)->orderBy('name')->get()
            ->filter(fn (Team $team) => $this->access->canAccess($this->user, $team))
            ->sortBy(fn (Team $team) => $team->id === $default->id ? 0 : 1)
            ->values();
    }

    /**
     * The tenant a tool call works in: the argument "tenant" (id) or the default. Null when the user may not
     * reach it, also when it exists in another partner's portfolio.
     */
    public function tenant(mixed $reference): ?Team
    {
        $tenants = $this->tenants();
        if ($reference === null || $reference === '') {
            return $tenants->first();
        }
        if (! is_numeric($reference)) {
            return null;
        }

        return $tenants->firstWhere('id', (int) $reference);
    }

    public function role(Team $tenant): ?TenantRole
    {
        return $this->access->roleIn($this->user, $tenant);
    }

    public function can(Team $tenant, TenantPermission $permission): bool
    {
        return $this->access->can($this->user, $tenant, $permission);
    }
}
