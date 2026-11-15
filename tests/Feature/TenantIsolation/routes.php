<?php

use App\Models\Alert;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Tests\Feature\TenantIsolation\Support\RouteAccess;
use Tests\Feature\TenantIsolation\Support\Scenario;

/*
|--------------------------------------------------------------------------
| Route access register (docs/spec.md 5.3, T06)
|--------------------------------------------------------------------------
|
| Every registered route, keyed by route name, or "METHOD uri" for unnamed routes. A new route
| without an entry fails RouteCoverageTest, and an entry for a removed route fails it too.
|
| For each entry RouteIsolationTest generates checks: guests are refused, a user of tenant A
| cannot reach tenant B or another user's resources (and nothing in B changes), roles listed in
| deniedRoles get 403, platform admin routes refuse everyone else, and no signed-in GET page
| shows anything from tenant B.
|
*/

$teamB = fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id]];
$teamA = fn (Scenario $s) => ['params' => ['team' => $s->tenantA->id]];
$tenantRoles = ['owner', 'admin', 'partner_advisor', 'editor', 'reader'];

$apiResource = function (Scenario $s, bool $foreign, string $action = 'read'): array {
    return app(TenantContext::class)->run($foreign ? $s->tenantB->id : $s->tenantA->id, function () use ($action) {
        $brand = Brand::factory()->create();
        $market = Market::factory()->create(['brand_id' => $brand->id]);
        $data = match ($action) {
            'store' => ['market_id' => $market->id, 'text' => 'A synthetic question?', 'intent' => 'category', 'funnel_stage' => 'awareness'],
            'update', 'destroy' => ['id' => Prompt::factory()->create(['market_id' => $market->id])->id, 'text' => 'Updated question?'],
            default => [],
        };

        return ['params' => ['brand' => $brand->id], 'data' => $data];
    });
};
$apiEntries = [];
foreach (['metrics', 'prompts', 'responses', 'sources', 'competitors'] as $resource) {
    $apiEntries['api.v1.'.$resource.'.index'] = RouteAccess::tenant(
        foreign: fn (Scenario $s) => $apiResource($s, true),
        own: fn (Scenario $s) => $apiResource($s, false),
    );
}
foreach (['store', 'update', 'destroy'] as $action) {
    $apiEntries['api.v1.prompts.'.$action] = RouteAccess::tenant(
        foreign: fn (Scenario $s) => $apiResource($s, true, $action),
        own: fn (Scenario $s) => $apiResource($s, false, $action),
        deniedRoles: ['reader'],
    );
}
// No resource parameter: the token determines the tenant, like the dashboard list. ApiIsolationTest
// verifies the collection contents and raw RLS against another tenant and a partner's client.
foreach (['brands', 'alerts', 'usage'] as $resource) {
    $apiEntries['api.v1.'.$resource.'.index'] = RouteAccess::user(own: fn () => []);
}

return [
    ...$apiEntries,
    // Public and system routes.
    'home' => RouteAccess::public(),
    // Sets the caller's own language (session, and the user row when signed in). No tenant data.
    'locale.update' => RouteAccess::public(),
    'GET up' => RouteAccess::public(),
    'sanctum.csrf-cookie' => RouteAccess::public(),

    // Login and password reset, for guests only.
    'login' => RouteAccess::guest(),
    'login.store' => RouteAccess::guest(),
    'password.request' => RouteAccess::guest(),
    'password.email' => RouteAccess::guest(),
    'password.reset' => RouteAccess::guest(),
    'password.update' => RouteAccess::guest(),
    'two-factor.login' => RouteAccess::guest(),
    'two-factor.login.store' => RouteAccess::guest(),
    // Login with Google: the provider is a fixed name, not a tenant resource, and the
    // callback creates a user only for an unexpired invitation (SocialLoginTest).
    'social.redirect' => RouteAccess::guest(),
    'social.callback' => RouteAccess::guest(),

    // The signed-in user's own account.
    'logout' => RouteAccess::user(),
    // The overview and the brand list have no resource id. They show the signed-in tenant only,
    // and the leak scan checks that tenant B never appears. The brand page is addressed by id.
    'dashboard' => RouteAccess::user(),
    'brands.index' => RouteAccess::user(),
    'brands.show' => RouteAccess::tenant(
        foreign: function (Scenario $s) {
            $id = app(TenantContext::class)->run(
                $s->tenantB->id,
                fn () => Brand::factory()->create()->id,
            );

            return ['params' => ['brand' => $id]];
        },
        own: function (Scenario $s) {
            $id = app(TenantContext::class)->run(
                $s->tenantA->id,
                fn () => Brand::factory()->create()->id,
            );

            return ['params' => ['brand' => $id]];
        },
    ),
    // Acknowledging an alert is the tenant admin's (alert rights, docs/spec.md 5.2). Addressed by id.
    'alerts.acknowledge' => RouteAccess::tenant(
        foreign: function (Scenario $s) {
            $id = app(TenantContext::class)->run(
                $s->tenantB->id,
                fn () => Alert::factory()->create()->id,
            );

            return ['params' => ['alert' => $id]];
        },
        own: function (Scenario $s) {
            $id = app(TenantContext::class)->run(
                $s->tenantA->id,
                fn () => Alert::factory()->create()->id,
            );

            return ['params' => ['alert' => $id]];
        },
        deniedRoles: ['editor', 'reader'],
    ),
    'GET api/user' => RouteAccess::user(),
    'profile.show' => RouteAccess::user(),
    'user-profile-information.update' => RouteAccess::user(),
    'user-password.update' => RouteAccess::user(),
    'current-user-photo.destroy' => RouteAccess::user(),
    'current-user.destroy' => RouteAccess::user(),
    'other-browser-sessions.destroy' => RouteAccess::user(),
    'password.confirm' => RouteAccess::user(),
    'password.confirm.store' => RouteAccess::user(),
    'password.confirmation' => RouteAccess::user(),
    // E-mail verification of the signed-in user's own address (ADR 0008). The link carries the
    // user's id, so another user's link must be refused.
    'verification.notice' => RouteAccess::user(),
    'verification.send' => RouteAccess::user(),
    'verification.verify' => RouteAccess::user(
        foreign: fn (Scenario $s) => ['params' => ['id' => $s->ownerB->id, 'hash' => sha1($s->ownerB->email)]],
        own: fn (Scenario $s) => ['params' => ['id' => $s->adminA->id, 'hash' => sha1($s->adminA->email)]],
    )->signed(),
    'two-factor.enable' => RouteAccess::user(),
    'two-factor.disable' => RouteAccess::user(),
    'two-factor.confirm' => RouteAccess::user(),
    'two-factor.qr-code' => RouteAccess::user(),
    'two-factor.secret-key' => RouteAccess::user(),
    'two-factor.recovery-codes' => RouteAccess::user(),
    'two-factor.regenerate-recovery-codes' => RouteAccess::user(),
    'api-tokens.index' => RouteAccess::user(),
    'api-tokens.store' => RouteAccess::user(),
    'api-tokens.update' => RouteAccess::user(
        foreign: fn (Scenario $s) => ['params' => ['token' => $s->tokenB->id], 'data' => ['name' => 'x', 'permissions' => ['write']]],
        own: fn (Scenario $s) => ['params' => ['token' => $s->tokenOfAdminA()->id], 'data' => ['name' => 'x', 'permissions' => ['write']]],
    ),
    'api-tokens.destroy' => RouteAccess::user(
        foreign: fn (Scenario $s) => ['params' => ['token' => $s->tokenB->id]],
        own: fn (Scenario $s) => ['params' => ['token' => $s->tokenOfAdminA()->id]],
    ),
    // Ends the admin's own acting-as session state only; no tenant data.
    'admin.acting-as.stop' => RouteAccess::user(),

    'bot-log-uploads.index' => RouteAccess::tenant(
        foreign: $teamB,
        own: function (Scenario $s): array {
            $s->brandIn($s->tenantA);
            $s->brandIn($s->tenantB);

            return ['params' => ['team' => $s->tenantA->id]];
        },
        deniedRoles: ['editor', 'reader'],
    ),
    'bot-log-uploads.store' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => $s->brandIn($s->tenantB)->id]],
        own: fn (Scenario $s) => [
            'params' => ['brand' => $s->brandIn($s->tenantA)->id],
            'data' => [
                'domain' => 'site.example.test',
                'log' => UploadedFile::fake()->createWithContent('access.log.gz', gzencode((string) file_get_contents(base_path('tests/Fixtures/bot-logs/combined.txt')))),
            ],
        ],
        deniedRoles: ['editor', 'reader'],
    ),

    // Tenants: the tenant switcher, the team page, members and invitations.
    'current-team.update' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['data' => ['team_id' => $s->tenantB->id]],
        own: fn (Scenario $s) => ['data' => ['team_id' => $s->tenantA->id]],
    ),
    'teams.show' => RouteAccess::tenant(foreign: $teamB, own: $teamA),
    'teams.update' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id], 'data' => ['name' => 'Renamed']],
        own: fn (Scenario $s) => ['params' => ['team' => $s->tenantA->id], 'data' => ['name' => 'Renamed']],
        deniedRoles: ['editor', 'reader'],
    ),
    'teams.destroy' => RouteAccess::tenant(foreign: $teamB, own: $teamA, deniedRoles: $tenantRoles),
    'team-members.store' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id], 'data' => ['email' => $s->adminA->email, 'role' => 'admin']],
        own: fn (Scenario $s) => ['params' => ['team' => $s->tenantA->id], 'data' => ['email' => 'someone@example.test', 'role' => 'reader']],
        deniedRoles: ['editor', 'reader'],
    ),
    'team-members.update' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id, 'user' => $s->memberB->id], 'data' => ['role' => 'admin']],
        own: fn (Scenario $s) => ['params' => ['team' => $s->tenantA->id, 'user' => $s->membersA['reader']->id], 'data' => ['role' => 'editor']],
        deniedRoles: ['editor', 'reader'],
    ),
    'team-members.destroy' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id, 'user' => $s->memberB->id]],
        own: fn (Scenario $s) => ['params' => ['team' => $s->tenantA->id, 'user' => $s->membersA['admin']->id]],
        deniedRoles: ['editor', 'reader'],
    ),
    'team-invitations.destroy' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['invitation' => $s->invitationB->id]],
        own: fn (Scenario $s) => ['params' => ['invitation' => $s->invitationInA()->id]],
        deniedRoles: ['editor', 'reader'],
    ),
    // The link is signed and sent to the invitee; without the signature it is refused.
    'team-invitations.accept' => RouteAccess::user(
        foreign: fn (Scenario $s) => ['params' => ['invitation' => $s->invitationB->id]],
        own: fn (Scenario $s) => ['params' => ['invitation' => $s->invitationForAdminA()->id]],
    )->signed(),

    // The Claude connection (docs/spec.md 15.2, ADR 0016). The MCP server takes only access tokens of a Claude
    // connection; its tools are checked tool by tool in McpToolIsolationTest (register: mcp-tools.php).
    'mcp' => RouteAccess::user(),
    'GET mcp' => RouteAccess::public(),
    'DELETE mcp' => RouteAccess::public(),
    // OAuth metadata, registration, token and revocation: Claude calls these without a session. A token or
    // refresh token only reaches its own connection (McpOAuthTest).
    'mcp.oauth.protected-resource' => RouteAccess::public(),
    'mcp.oauth.protected-resource.nested' => RouteAccess::public(),
    'mcp.oauth.authorization-server' => RouteAccess::public(),
    'mcp.oauth.authorization-server.nested' => RouteAccess::public(),
    'mcp.oauth.register' => RouteAccess::public(),
    'mcp.oauth.token' => RouteAccess::public(),
    'mcp.oauth.revoke' => RouteAccess::public(),
    // Consent: the tenant must be one the user can select (McpOAuthTest).
    'mcp.oauth.authorize' => RouteAccess::user(),
    'mcp.oauth.decide' => RouteAccess::user(),
    // The user's own Claude connections.
    'mcp.connections' => RouteAccess::user(),
    'mcp.connections.destroy' => RouteAccess::user(
        foreign: fn (Scenario $s) => ['params' => ['connection' => $s->mcpConnectionOf($s->ownerB, $s->tenantB)->id]],
        own: fn (Scenario $s) => ['params' => ['connection' => $s->mcpConnectionOf($s->adminA, $s->tenantA)->id]],
    ),

    // Platform admin only.
    'teams.create' => RouteAccess::platformAdmin(),
    'teams.store' => RouteAccess::platformAdmin(fn (Scenario $s) => ['data' => ['name' => 'New tenant']]),
    'admin.acting-as.start' => RouteAccess::platformAdmin(fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id], 'data' => ['reason' => 'Isolation test reason']]),
];
