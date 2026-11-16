<?php

namespace App\Http\Controllers\Jetstream;

use App\Api\TokenTenant;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\AuditLog;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Jetstream\Http\Controllers\Inertia\ApiTokenController as JetstreamApiTokenController;
use Laravel\Jetstream\Jetstream;

/**
 * Jetstream's API token controller. Jetstream's destroy() calls first()->delete(), so another
 * user's token id gave a 500; here it gives 404, like update().
 */
class ApiTokenController extends JetstreamApiTokenController
{
    public function index(Request $request)
    {
        $user = $request->user();
        $access = app(TenantAccess::class);
        $tenant = Team::query()->find(app(TenantContext::class)->id()) ?? $user->currentTeam;

        return Jetstream::inertia()->render($request, 'API/Index', [
            'tokens' => $user->tokens->map(function ($token) use ($user, $access) {
                $tenantId = TokenTenant::id($token);
                $tenant = $tenantId === null ? null : Team::query()->find($tenantId);

                return $token->toArray() + [
                    'last_used_ago' => $token->last_used_at?->diffForHumans(),
                    'tenant_name' => $tenant instanceof Team && $access->canSelect($user, $tenant) ? $tenant->name : null,
                ];
            }),
            'createTenantName' => $tenant instanceof Team && $access->canSelect($user, $tenant) ? $tenant->name : null,
            'availablePermissions' => Jetstream::$permissions,
            'defaultPermissions' => Jetstream::$defaultPermissions,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);
        $user = $request->user();
        $tenant = Team::query()->find(app(TenantContext::class)->id()) ?? $user?->currentTeam;
        abort_unless($user instanceof User && $tenant instanceof Team && app(TenantAccess::class)->canSelect($user, $tenant), 403);
        $token = $user->createToken($validated['name'], [
            ...Jetstream::validPermissions($validated['permissions'] ?? []),
            'tenant:'.$tenant->id,
        ]);
        app(AuditLog::class)->apiTokenCreated($user, $tenant, $token->accessToken->id, $validated['name'], array_values($token->accessToken->abilities));

        return back()->with('flash', ['token' => explode('|', $token->plainTextToken, 2)[1]]);
    }

    public function update(Request $request, $tokenId): RedirectResponse
    {
        $validated = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);
        $token = $request->user()->tokens()->whereKey($tokenId)->firstOrFail();
        $tenantId = TokenTenant::id($token);
        $from = array_values($token->abilities ?? []);
        // Never bind a legacy token implicitly, and never rebind a token after a team switch.
        $token->forceFill(['abilities' => [
            ...Jetstream::validPermissions($validated['permissions'] ?? []),
            ...($tenantId === null ? [] : ['tenant:'.$tenantId]),
        ]])->save();
        $this->audit($tenantId, fn (Team $tenant) => app(AuditLog::class)->apiTokenUpdated($request->user(), $tenant, $token->id, $from, array_values($token->abilities)));

        return back(303);
    }

    /**
     * @param  string  $tokenId
     */
    public function destroy(Request $request, $tokenId): RedirectResponse
    {
        $token = $request->user()->tokens()->where('id', $tokenId)->firstOrFail();
        $token->delete();
        $this->audit(TokenTenant::id($token), fn (Team $tenant) => app(AuditLog::class)->apiTokenDeleted($request->user(), $tenant, $token->id, $token->name));

        return back(303);
    }

    /**
     * Token changes are logged in the tenant the token is bound to (T39-F6). A legacy token without a binding,
     * or one for a tenant that is gone, has no tenant log to go to.
     *
     * @param  callable(Team): void  $write
     */
    private function audit(?int $tenantId, callable $write): void
    {
        $tenant = $tenantId === null ? null : Team::query()->find($tenantId);
        if ($tenant instanceof Team) {
            $write($tenant);
        }
    }
}
