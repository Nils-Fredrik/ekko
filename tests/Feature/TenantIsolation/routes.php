<?php

use App\Models\Brand;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Tenancy\TenantContext;
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

function isolationBrandId(int $tenantId): int
{
    return app(TenantContext::class)->run($tenantId, fn () => Brand::factory()->create()->id);
}

/** @return array{brand: int} */
function isolationMarket(int $tenantId): array
{
    return app(TenantContext::class)->run($tenantId, function (): array {
        $brand = Brand::factory()->create();
        Market::factory()->for($brand)->create(['country' => 'NO']);

        return ['brand' => $brand->id];
    });
}

/** @return array{brand: int, prompt: int} */
function isolationPrompt(int $tenantId): array
{
    return app(TenantContext::class)->run($tenantId, function (): array {
        $brand = Brand::factory()->create();
        $market = Market::factory()->for($brand)->create(['country' => 'NO']);
        $prompt = Prompt::factory()->for($market)->create();

        return ['brand' => $brand->id, 'prompt' => $prompt->id];
    });
}

/** @return array{brand: int, response: int} */
function isolationResponse(int $tenantId): array
{
    return app(TenantContext::class)->run($tenantId, function (): array {
        $brand = Brand::factory()->create();
        $market = Market::factory()->for($brand)->create();
        $prompt = Prompt::factory()->for($market)->create();
        $batch = RunBatch::factory()->create(['market_id' => $market->id]);
        $engine = Engine::factory()->create();
        $run = Run::factory()->create([
            'run_batch_id' => $batch->id,
            'prompt_id' => $prompt->id,
            'engine_key' => $engine->key,
        ]);
        $response = Response::factory()->create([
            'run_id' => $run->id,
            'engine_key' => $engine->key,
            'answer_text' => 'Et svar om kaffe.',
        ]);

        return ['brand' => $brand->id, 'response' => $response->id];
    });
}

$teamB = fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id]];
$teamA = fn (Scenario $s) => ['params' => ['team' => $s->tenantA->id]];
$tenantRoles = ['owner', 'admin', 'partner_advisor', 'editor', 'reader'];

return [
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
    'brands.prompts.index' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)]],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)]],
    ),
    'brands.prompts.export' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)]],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)]],
    ),
    'brands.prompts.import' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => [
            'params' => ['brand' => isolationBrandId($s->tenantB->id)],
            'data' => ['csv' => "text,market\nHvilken kaffe er best?,NO\n"],
        ],
        own: fn (Scenario $s) => [
            'params' => ['brand' => isolationMarket($s->tenantA->id)['brand']],
            'data' => ['csv' => "text,market\nHvilken kaffe er best?,NO\n"],
        ],
        deniedRoles: ['reader'],
    ),
    'brands.prompts.show' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => isolationPrompt($s->tenantB->id)],
        own: fn (Scenario $s) => ['params' => isolationPrompt($s->tenantA->id)],
    ),
    'brands.prompts.update' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => [
            'params' => isolationPrompt($s->tenantB->id),
            'data' => ['text' => 'En ny formulering av sporsmalet?'],
        ],
        own: fn (Scenario $s) => [
            'params' => isolationPrompt($s->tenantA->id),
            'data' => ['text' => 'En ny formulering av sporsmalet?'],
        ],
        deniedRoles: ['reader'],
    ),
    'brands.responses.raw' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => isolationResponse($s->tenantB->id)],
        own: fn (Scenario $s) => ['params' => isolationResponse($s->tenantA->id)],
    ),
    'brands.competitors.index' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)]],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)]],
    ),
    'brands.competitors.store' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => [
            'params' => ['brand' => isolationBrandId($s->tenantB->id)],
            'data' => ['name' => 'Kaffebonna'],
        ],
        own: fn (Scenario $s) => [
            'params' => ['brand' => isolationBrandId($s->tenantA->id)],
            'data' => ['name' => 'Kaffebonna'],
        ],
        deniedRoles: ['reader'],
    ),
    'brands.sources' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)]],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)]],
    ),
    'brands.crawlers' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)]],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)]],
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

    // Platform admin only.
    'teams.create' => RouteAccess::platformAdmin(),
    'teams.store' => RouteAccess::platformAdmin(fn (Scenario $s) => ['data' => ['name' => 'New tenant']]),
    'admin.acting-as.start' => RouteAccess::platformAdmin(fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id], 'data' => ['reason' => 'Isolation test reason']]),
];
