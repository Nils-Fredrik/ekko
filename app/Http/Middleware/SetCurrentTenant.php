<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Tenancy\ActingAs;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant the request works in (docs/spec.md 5.3 and 5.4):
 * - the tenant a platform admin acts as (shown as a banner, logged), otherwise
 * - the user's current tenant (Jetstream's current team), when TenantAccess allows it:
 *   a membership, or a client tenant reached as partner advisor.
 */
final class SetCurrentTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAccess $access,
        private readonly ActingAs $actingAs,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Start every request from a clean state, also where the container is reused between
        // requests (tests, long-running workers): a tenant must never carry over.
        $this->context->forget();
        $this->access->flush();

        $user = $request->user();

        if ($user instanceof User) {
            // Drop a currentTeam relation set while acting as a tenant in an earlier request on the
            // same user object, so the navigation follows the stored current_team_id again.
            $user->unsetRelation('currentTeam');

            $tenant = $this->tenantFor($user);

            if ($tenant !== null && $this->access->canAccess($user, $tenant)) {
                $this->context->set($tenant->id);

                // While acting as a tenant, the navigation (Jetstream's auth.user.current_team, the
                // team settings link) must show that tenant, not the stored current_team_id.
                if ($this->actingAs->tenantIdFor($user) === $tenant->id) {
                    $user->setRelation('currentTeam', $tenant);
                }
            }
        }

        return $next($request);
    }

    private function tenantFor(User $user): ?Team
    {
        $actingAsId = $this->actingAs->tenantIdFor($user);

        if ($actingAsId !== null) {
            return Team::query()->find($actingAsId);
        }

        if ($user->current_team_id === null) {
            return null;
        }

        return Team::query()->find($user->current_team_id);
    }
}
