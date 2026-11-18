<?php

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\Team;
use App\Models\Topic;
use App\Onboarding\AnalysisStore;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
$brandOf = function (Scenario $s, bool $foreign): int {
    return app(TenantContext::class)->run(
        ($foreign ? $s->tenantB : $s->tenantA)->id,
        fn () => Brand::factory()->create()->id,
    );
};

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
    'admin.tenants.index' => RouteAccess::platformAdmin(),
    'admin.tenants.store' => RouteAccess::platformAdmin(own: fn () => ['data' => [
        'name' => 'Isolasjon',
        'plan_id' => Plan::factory()->create()->id,
    ]]),
    'admin.tenants.update' => RouteAccess::platformAdmin(own: function () {
        $plan = Plan::factory()->create();
        $team = Team::factory()->tenant()->withPlan($plan)->create();

        return ['params' => ['team' => $team->id], 'data' => [
            'name' => $team->name,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]];
    }),
    'admin.plans.index' => RouteAccess::platformAdmin(),
    'admin.plans.store' => RouteAccess::platformAdmin(own: fn () => ['data' => [
        'name' => 'Isolasjonspakke',
        'max_frequency' => 'weekly',
        'max_repetitions' => 1,
        'allowed_engines' => [],
        'engine_max_frequencies' => [],
    ]]),
    'admin.plans.update' => RouteAccess::platformAdmin(own: function () {
        $plan = Plan::factory()->create();

        return ['params' => ['plan' => $plan->id], 'data' => [
            'name' => $plan->name,
            'max_frequency' => 'daily',
            'max_repetitions' => 1,
            'allowed_engines' => [],
            'engine_max_frequencies' => [],
        ]];
    }),
    'admin.engines.index' => RouteAccess::platformAdmin(),
    'admin.engines.update' => RouteAccess::platformAdmin(own: function () {
        $engine = Engine::factory()->create();

        return ['params' => ['engine' => $engine->key], 'data' => ['model_id' => 'demo', 'is_enabled' => true]];
    }),
    'admin.engines.prices.store' => RouteAccess::platformAdmin(own: function () {
        $engine = Engine::factory()->create();

        return ['params' => ['engine' => $engine->key], 'data' => [
            'valid_from' => now('UTC')->addDay()->format('Y-m-d H:i:s'),
            'price_per_call_usd' => '0.01000000',
            'price_per_1k_input_tokens_usd' => '0',
            'price_per_1k_output_tokens_usd' => '0',
            'price_per_search_usd' => '0',
        ]];
    }),
    'admin.billing.index' => RouteAccess::platformAdmin(),
    'admin.billing.export' => RouteAccess::platformAdmin(own: fn (Scenario $s) => ['data' => ['month' => '2026-10']]),
    'admin.billing.markup' => RouteAccess::platformAdmin(own: fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id], 'data' => ['markup_percent' => '10.0000']]),
    'internal.extraction-poll' => RouteAccess::service(),
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
    'brands.settings' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => $brandOf($s, true)]],
        own: fn (Scenario $s) => ['params' => ['brand' => $brandOf($s, false)]],
    ),
    'brands.settings.update' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => $brandOf($s, true)], 'data' => ['name' => 'Other']],
        own: fn (Scenario $s) => ['params' => ['brand' => $brandOf($s, false)], 'data' => ['name' => 'Renamed']],
        deniedRoles: ['editor', 'reader'],
    ),
    'alerts.index' => RouteAccess::user(),
    'alerts.rules.store' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['data' => ['brand_id' => $brandOf($s, true), 'type' => 'quota', 'is_active' => true]],
        own: fn (Scenario $s) => ['data' => ['brand_id' => $brandOf($s, false), 'type' => 'quota', 'is_active' => true]],
        deniedRoles: ['editor', 'reader'],
    ),
    'alerts.rules.update' => RouteAccess::tenant(
        foreign: function (Scenario $s) {
            $id = app(TenantContext::class)->run($s->tenantB->id, fn () => AlertRule::factory()->create()->id);

            return ['params' => ['alertRule' => $id], 'data' => ['is_active' => false]];
        },
        own: function (Scenario $s) {
            $id = app(TenantContext::class)->run($s->tenantA->id, fn () => AlertRule::factory()->create()->id);

            return ['params' => ['alertRule' => $id], 'data' => ['threshold' => '5', 'is_active' => true]];
        },
        deniedRoles: ['editor', 'reader'],
    ),
    'reports.index' => RouteAccess::user(),
    'reports.schedules.store' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['data' => [
            'brand_id' => $brandOf($s, true), 'cadence' => 'weekly', 'recipients' => ['reports@example.test'], 'locale' => 'nb', 'branding' => 'tenant',
        ]],
        own: fn (Scenario $s) => ['data' => [
            'brand_id' => $brandOf($s, false), 'cadence' => 'weekly', 'recipients' => ['reports@example.test'], 'locale' => 'nb', 'branding' => 'tenant',
        ]],
        deniedRoles: ['editor', 'reader'],
    ),
    'reports.schedules.update' => RouteAccess::tenant(
        foreign: function (Scenario $s) {
            $id = app(TenantContext::class)->run($s->tenantB->id, fn () => ReportSchedule::factory()->create()->id);

            return ['params' => ['schedule' => $id], 'data' => ['cadence' => 'monthly', 'recipients' => ['reports@example.test'], 'locale' => 'nb', 'branding' => 'tenant']];
        },
        own: function (Scenario $s) {
            $id = app(TenantContext::class)->run($s->tenantA->id, fn () => ReportSchedule::factory()->create()->id);

            return ['params' => ['schedule' => $id], 'data' => ['cadence' => 'monthly', 'recipients' => ['reports@example.test'], 'locale' => 'sv', 'branding' => 'partner']];
        },
        deniedRoles: ['editor', 'reader'],
    ),
    'reports.download' => RouteAccess::tenant(
        foreign: function (Scenario $s) {
            $id = app(TenantContext::class)->run($s->tenantB->id, fn () => Report::factory()->create()->id);

            return ['params' => ['report' => $id]];
        },
        own: function (Scenario $s) {
            $id = app(TenantContext::class)->run($s->tenantA->id, function () {
                $report = Report::factory()->create();
                $path = 'reports/'.$report->getAttribute('tenant_id').'/'.$report->id.'.pdf';
                $report->forceFill(['pdf_uri' => $path])->save();
                Storage::disk(config()->string('ekko.reports.disk'))->put($path, "%PDF-1.4\n");

                return $report->id;
            });

            return ['params' => ['report' => $id]];
        },
    ),
    'usage.index' => RouteAccess::user(own: fn () => [], deniedRoles: ['editor', 'reader']),
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
    // The onboarding wizard (ADR 0018). Proposals are JSON and nothing is saved. Starting a brand analysis has no
    // resource id: it creates a proposal in the signed-in tenant only (OnboardingTest covers the roles without
    // brands.manage, and that nothing is written to tenant B). The model, DNS and HTTP are fakes in the register.
    'onboarding.analyses.store' => RouteAccess::user(
        own: function (Scenario $s) {
            OnboardingFakes::bind();

            return ['data' => ['name' => 'Eksempel', 'primary_domain' => 'eksempel.no', 'country' => 'NO', 'language' => 'nb']];
        },
    ),
    'brands.markets.create' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)]],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)]],
        deniedRoles: ['editor', 'reader'],
    ),
    'onboarding.wizard' => RouteAccess::user(own: fn () => [], deniedRoles: ['editor', 'reader']),
    // Confirmation writes only in the current tenant; foreign proposals are 404, readers/editors 403.
    'onboarding.analyses.confirm' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => [
            'params' => ['analysis' => app(AnalysisStore::class)->create($s->tenantB->id, $s->ownerB->id, 'brand', ['name' => $s->marker])->id],
            'data' => [
                'name' => 'Eksempel',
                'primary_domain' => 'eksempel.no',
                'country' => 'NO',
                'language' => 'nb',
            ],
        ],
        own: function (Scenario $s) {
            $store = app(AnalysisStore::class);
            $analysis = $store->create($s->tenantA->id, $s->adminA->id, 'brand', [
                'name' => 'Eksempel',
                'primary_domain' => 'eksempel.no',
                'country' => 'NO',
                'language' => 'nb',
            ]);
            $analysis->status = 'ready';
            $store->save($analysis);

            return [
                'params' => ['analysis' => $analysis->id],
                'data' => [
                    'name' => 'Eksempel',
                    'primary_domain' => 'eksempel.no',
                    'country' => 'NO',
                    'language' => 'nb',
                    'topics' => [['name' => 'Sko', 'cross_market_key' => 'shoes']],
                    'prompts' => [[
                        'text' => 'Hvilke sko er best?',
                        'cross_market_key' => 'shoes',
                        'intent' => 'category',
                        'funnel_stage' => 'awareness',
                        'priority' => 'core',
                    ]],
                ],
            ];
        },
        deniedRoles: ['editor', 'reader'],
    ),
    // Proposals are addressed by id under their tenant; a foreign id is not found.
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
    'teams.slack.update' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id], 'data' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/xxx']],
        own: fn (Scenario $s) => ['params' => ['team' => $s->tenantA->id], 'data' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/xxx']],
        deniedRoles: ['editor', 'reader'],
    ),
    'teams.branding.update' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id], 'data' => ['primary' => '#112233']],
        own: fn (Scenario $s) => ['params' => ['team' => $s->tenantA->id], 'data' => ['primary' => '#112233']],
        deniedRoles: ['editor', 'reader'],
    ),
    'teams.api-keys.store' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id]],
        own: fn (Scenario $s) => ['params' => ['team' => $s->tenantA->id]],
        deniedRoles: ['editor', 'reader'],
    ),
    'teams.byok-budget.update' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id]],
        own: fn (Scenario $s) => ['params' => ['team' => $s->tenantA->id]],
        deniedRoles: ['editor', 'reader'],
    ),
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

    'admin.usage' => RouteAccess::platformAdmin(),

    // Platform admin only.
    'teams.create' => RouteAccess::platformAdmin(),
    'teams.store' => RouteAccess::platformAdmin(fn (Scenario $s) => ['data' => ['name' => 'New tenant']]),
    'admin.acting-as.start' => RouteAccess::platformAdmin(fn (Scenario $s) => ['params' => ['team' => $s->tenantB->id], 'data' => ['reason' => 'Isolation test reason']]),
];
