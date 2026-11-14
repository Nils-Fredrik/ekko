<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the signed-in user's current tenant (Jetstream's current team) in the tenant context,
 * but only when the user belongs to it. Partner access and "act as tenant" come with
 * TenantAccess in T05, and the database layer (ApplyTenantRls) in T04.
 */
final class SetCurrentTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->current_team_id !== null) {
            $team = $user->currentTeam;

            if ($team !== null && $user->belongsToTeam($team)) {
                $this->context->set((int) $team->getKey());
            }
        }

        return $next($request);
    }
}
