<?php

use App\Models\Brand;
use App\Models\Market;
use App\Models\Topic;
use App\Onboarding\AnalysisStore;
use App\Tenancy\TenantContext;
use Tests\Feature\TenantIsolation\Support\RouteAccess;
use Tests\Feature\TenantIsolation\Support\Scenario;
use Tests\Support\Onboarding\OnboardingFakes;

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
    // The onboarding wizard (ADR 0018). Proposals are JSON and nothing is saved. Starting a brand analysis has no
    // resource id: it creates a proposal in the signed-in tenant only (OnboardingTest covers the roles without
    // brands.manage, and that nothing is written to tenant B). The model, DNS and HTTP are fakes in the register.
    'onboarding.analyses.store' => RouteAccess::user(
        own: function (Scenario $s) {
            OnboardingFakes::bind();

            return ['data' => ['name' => 'Eksempel', 'primary_domain' => 'eksempel.no', 'country' => 'NO', 'language' => 'nb']];
        },
    ),
    // The proposal is addressed by id and kept per tenant: B's id is not found from A (404), and A's role
    // without brands.manage gets 403.
    'onboarding.analyses.show' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['analysis' => app(AnalysisStore::class)->create($s->tenantB->id, $s->ownerB->id, 'brand', ['name' => $s->marker])->id]],
        own: fn (Scenario $s) => ['params' => ['analysis' => app(AnalysisStore::class)->create($s->tenantA->id, $s->adminA->id, 'brand', ['name' => 'Own'])->id]],
        deniedRoles: ['editor', 'reader'],
    ),
    // The brand is bound under the tenant scope: B's brand is a 404 from A.
    'brands.market-proposals.store' => RouteAccess::tenant(
        foreign: function (Scenario $s) {
            $id = app(TenantContext::class)->run(
                $s->tenantB->id,
                fn () => tap(Brand::factory()->create(), fn (Brand $b) => Topic::factory()->create(['brand_id' => $b->id, 'cross_market_key' => 'k']))->id,
            );

            return ['params' => ['brand' => $id], 'data' => ['country' => 'SE', 'language' => 'sv']];
        },
        own: function (Scenario $s) {
            OnboardingFakes::bind(OnboardingFakes::marketJson(['k']));
            $id = app(TenantContext::class)->run(
                $s->tenantA->id,
                fn () => tap(Brand::factory()->create(), fn (Brand $b) => Topic::factory()->create(['brand_id' => $b->id, 'cross_market_key' => 'k']))->id,
            );

            return ['params' => ['brand' => $id], 'data' => ['country' => 'SE', 'language' => 'sv']];
        },
        deniedRoles: ['editor', 'reader'],
    ),
    // An existing market of B is a 404 from A, and the cost estimate needs markets.manage.
    'onboarding.cost-estimate' => RouteAccess::tenant(
        foreign: function (Scenario $s) {
            $id = app(TenantContext::class)->run($s->tenantB->id, fn () => Market::factory()->create()->id);

            return ['data' => ['market_id' => $id, 'engines' => [['engine_key' => 'chatgpt_ui', 'frequency' => 'daily']]]];
        },
        own: fn (Scenario $s) => ['data' => ['prompt_count' => 10, 'engines' => [['engine_key' => 'chatgpt_ui', 'frequency' => 'daily']]]],
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
