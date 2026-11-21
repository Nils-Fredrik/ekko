<?php

use App\Models\Action;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Competitor;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\ReportSchedule;
use App\Models\Response;
use App\Models\Run;
use App\Models\RunBatch;
use App\Models\SavedView;
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

/** @return array{report: int, link: int} */
function isolationReportLink(int $tenantId): array
{
    return app(TenantContext::class)->run($tenantId, function (): array {
        $report = Report::factory()->create();
        $link = ReportLink::query()->create([
            'report_id' => $report->id, 'public_id' => bin2hex(random_bytes(16)), 'token_hash' => hash('sha256', random_bytes(32)),
            'expires_at' => now()->addDay(),
        ]);

        return ['report' => $report->id, 'link' => $link->id];
    });
}

/** @return array{brand: int, action: int, step: int} */
function isolationAction(int $tenantId): array
{
    return app(TenantContext::class)->run($tenantId, function (): array {
        $brand = Brand::factory()->create();
        $action = Action::factory()->create(['brand_id' => $brand->id]);

        return ['brand' => $brand->id, 'action' => $action->id, 'step' => 0];
    });
}

/** @return array{brand: int, fact: int} */
function isolationFact(int $tenantId): array
{
    return app(TenantContext::class)->run($tenantId, function (): array {
        $brand = Brand::factory()->create();
        $fact = BrandFact::factory()->create(['brand_id' => $brand->id, 'is_active' => true]);

        return ['brand' => $brand->id, 'fact' => $fact->id];
    });
}

/** @return array{brand: int, competitor: int} */
function isolationCompetitor(int $tenantId): array
{
    return app(TenantContext::class)->run($tenantId, function (): array {
        $competitor = Competitor::factory()->create();

        return ['brand' => $competitor->brand_id, 'competitor' => $competitor->id];
    });
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

// T50: the token still selects exactly one tenant; nested body IDs are tested separately too.
$extension = function (Scenario $s, bool $foreign, string $resource, string $operation): array {
    return app(TenantContext::class)->run($foreign ? $s->tenantB->id : $s->tenantA->id, function () use ($resource, $operation): array {
        $brand = Brand::factory()->create();
        if ($resource === 'report-links' || $resource === 'reports') {
            $report = Report::factory()->create();
            $data = [];
            if ($operation === 'destroy') {
                $data['id'] = ReportLink::query()->create(['report_id' => $report->id, 'public_id' => bin2hex(random_bytes(16)),
                    'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addDay()])->id;
            }

            return ['params' => ['report' => $report->id], 'data' => $data];
        }
        $params = ['brand' => $brand->id];
        $data = [];
        if ($resource === 'actions') {
            if ($operation === 'store') {
                $data = ['title' => 'Synthetic action'];
            }
            if (in_array($operation, ['update', 'show'], true)) {
                $action = Action::factory()->create(['brand_id' => $brand->id, 'status' => 'planned']);
                if ($operation === 'show') {
                    $params = ['action' => $action->id];
                } else {
                    $data = ['id' => $action->id, 'status' => 'in_progress'];
                }
            }
        }
        if ($resource === 'facts') {
            if ($operation === 'store') {
                $data = ['text' => 'Synthetic fact', 'is_active' => false];
            }
            if (in_array($operation, ['update', 'destroy'], true)) {
                $fact = BrandFact::factory()->create(['brand_id' => $brand->id]);
                $data = ['id' => $fact->id, 'is_active' => false];
            }
        }

        return ['params' => $params, 'data' => $data];
    });
};
foreach (['actions' => ['index', 'show', 'store', 'update'], 'facts' => ['index', 'store', 'update', 'destroy'],
    'claims' => ['index'], 'reports' => ['show'], 'report-links' => ['store', 'destroy']] as $resource => $operations) {
    foreach ($operations as $operation) {
        $writes = ! in_array($operation, ['index', 'show'], true);
        $apiEntries['api.v1.'.$resource.'.'.$operation] = RouteAccess::tenant(
            foreign: fn (Scenario $s) => $extension($s, true, $resource, $operation),
            own: fn (Scenario $s) => $extension($s, false, $resource, $operation),
            deniedRoles: $writes ? ($resource === 'actions' ? ['reader'] : ['editor', 'reader']) : [],
        );
    }
}
$apiEntries['api.v1.reports.index'] = RouteAccess::user(own: fn () => []);
foreach (['index', 'store'] as $operation) {
    $apiEntries['api.v1.pitches.'.$operation] = RouteAccess::user(
        own: fn () => ['data' => ['name' => 'Synthetic pitch', 'domain' => 'example.test', 'country' => 'NO', 'language' => 'nb']],
        deniedRoles: ['owner', 'admin', 'editor', 'reader'],
    );
}

return [
    'admin.tenants.index' => RouteAccess::platformAdmin(),
    'admin.pitches.store' => RouteAccess::platformAdmin(own: function () {
        $partner = Team::factory()->tenant()->create();

        return ['data' => ['name' => 'Pitch test', 'partner_team_id' => $partner->id]];
    }),
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
    // Hourly recovery check (T40-F4): Scheduler identity only, no parameters; covered by Work/WorkWakeEndpointTest.
    'internal.work-recover' => RouteAccess::service(),
    // Cloud Tasks wake-ups (T40-F4): the wake-up identity and audience only. The body's tenant_id/signal_id are the only
    // parameters; Work/WorkWakeEndpointTest attacks them with another tenant's signal, mismatched pairs, closed and
    // personal teams, extra fields and wrong types, and checks that nothing of the other tenant is queued or changed.
    'internal.work.wake' => RouteAccess::service(),
    'internal.work.queue-wake' => RouteAccess::service(),
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
    // The callback also finishes a confirmation for a signed-in user, only for an account linked to that user
    // (SocialConfirmTest).
    'social.callback' => RouteAccess::public(),
    'social.confirm' => RouteAccess::user(own: function (): array {
        // A provider without credentials answers 404, so the positive control configures Google.
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);

        return ['params' => ['provider' => 'google']];
    })->withoutForeignCheck('the provider is a fixed name, and only an account linked to the signed-in user confirms (SocialConfirmTest)'),

    // The signed-in user's own account.
    'logout' => RouteAccess::user(),
    // The overview and the brand list have no resource id. They show the signed-in tenant only,
    // and the leak scan checks that tenant B never appears. The brand page is addressed by id.
    'dashboard' => RouteAccess::user(),
    // No resource id. The query searches the signed-in tenant only. CommandPaletteTest checks
    // that a name from tenant B never comes back, and the leak scan checks the empty query.
    'palette.search' => RouteAccess::user(),
    // Chart examples with fixed test data (U02). Registered only in local and testing (routes/dev.php).
    'dev.charts' => RouteAccess::user(),
    // Shared components with fixed test data (U18). Registered only in local and testing (routes/dev.php).
    'dev.components' => RouteAccess::user(),
    // No resource id. A partner advisor sees only clients of the current partner tenant.
    // PartnerOverviewTest checks that another partner's client never appears.
    'partner.index' => RouteAccess::user(),
    'partner.pitches.store' => RouteAccess::user(
        own: function (Scenario $s) {
            $s->tenantA->forceFill(['plan_id' => Plan::factory()->create()->id])->save();

            return ['data' => ['name' => 'Pitch test']];
        },
        deniedRoles: ['owner', 'admin', 'editor', 'reader'],
    )->withoutForeignCheck('Uses the current partner only; cross-partner authorization is covered by PitchProjectsTest.'),
    // T49: the pitch list of the current partner. PitchPagesTest checks that another partner's pitch never appears.
    'pitches.index' => RouteAccess::user(own: fn () => [], deniedRoles: ['owner', 'admin', 'editor', 'reader']),
    'pitches.open' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['team' => Team::factory()->clientOf($s->tenantB)->create(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)])->id]],
        own: fn (Scenario $s) => ['params' => ['team' => Team::factory()->clientOf($s->tenantA)->create(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)])->id]],
        deniedRoles: ['owner', 'admin', 'editor', 'reader'],
    ),
    'pitches.convert' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['team' => Team::factory()->clientOf($s->tenantB)->create(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)])->id]],
        own: function (Scenario $s) {
            $s->tenantA->forceFill(['plan_id' => Plan::factory()->create()->id])->save();

            return ['params' => ['team' => Team::factory()->clientOf($s->tenantA)->create(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)])->id]];
        },
        deniedRoles: ['owner', 'admin', 'editor', 'reader'],
    ),
    'pitches.report' => RouteAccess::tenant(
        foreign: function (Scenario $s) {
            $id = app(TenantContext::class)->run($s->tenantB->id, fn () => Brand::factory()->create()->id);

            return ['params' => ['brand' => $id]];
        },
        own: function (Scenario $s) {
            $s->tenantA->forceFill(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)])->save();
            $id = app(TenantContext::class)->run($s->tenantA->id, fn () => Brand::factory()->create()->id);

            return ['params' => ['brand' => $id]];
        },
    ),
    'pitches.report.pdf' => RouteAccess::tenant(
        foreign: function (Scenario $s) {
            $id = app(TenantContext::class)->run($s->tenantB->id, fn () => Brand::factory()->create()->id);

            return ['params' => ['brand' => $id]];
        },
        own: function (Scenario $s) {
            $s->tenantA->forceFill(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)])->save();
            $id = app(TenantContext::class)->run($s->tenantA->id, fn () => Brand::factory()->create()->id);

            return ['params' => ['brand' => $id]];
        },
    ),
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
    // The measurement plan (T53): another tenant's brand is a 404; a foreign market ID on an own brand is
    // rejected without writing in tests/Feature/MeasurementPlan/PlanAccessTest.php.
    'brands.plan.show' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => $brandOf($s, true)]],
        own: fn (Scenario $s) => ['params' => ['brand' => $brandOf($s, false)]],
    ),
    'brands.plan.estimate' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => $brandOf($s, true)], 'data' => ['standard' => ['engines' => []]]],
        own: fn (Scenario $s) => ['params' => ['brand' => $brandOf($s, false)], 'data' => ['standard' => ['engines' => []]]],
        deniedRoles: ['editor', 'reader'],
    ),
    'brands.plan.update' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => $brandOf($s, true)], 'data' => ['standard' => ['engines' => []]]],
        own: fn (Scenario $s) => ['params' => ['brand' => $brandOf($s, false)], 'data' => ['standard' => ['engines' => []]]],
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
    'reports.shared' => RouteAccess::public()->withoutForeignCheck('Public bearer credential is tested separately with two tenants.'),
    'brands.views.store' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)], 'data' => ['name' => 'Test view', 'page' => 'brands.show', 'filters' => ['days' => 7]]],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)], 'data' => ['name' => 'Test view', 'page' => 'brands.show', 'filters' => ['days' => 7]]],
    ),
    'brands.views.destroy' => RouteAccess::tenant(
        foreign: function (Scenario $s): array {
            $brand = isolationBrandId($s->tenantB->id);
            $view = app(TenantContext::class)->run($s->tenantB->id, fn () => SavedView::query()->create([
                'brand_id' => $brand, 'user_id' => $s->ownerB->id, 'page' => 'brands.show', 'name' => 'Private view', 'filters' => ['days' => 7],
            ]));

            return ['params' => ['brand' => $brand, 'savedView' => $view->id]];
        },
        own: function (Scenario $s): array {
            $brand = isolationBrandId($s->tenantA->id);
            $view = app(TenantContext::class)->run($s->tenantA->id, fn () => SavedView::query()->create([
                'brand_id' => $brand, 'user_id' => $s->adminA->id, 'page' => 'brands.show', 'name' => 'Own view', 'filters' => ['days' => 7],
            ]));

            return ['params' => ['brand' => $brand, 'savedView' => $view->id]];
        },
    ),
    'reports.links.store' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['report' => isolationReportLink($s->tenantB->id)['report']], 'data' => ['days' => 1]],
        own: fn (Scenario $s) => ['params' => ['report' => isolationReportLink($s->tenantA->id)['report']], 'data' => ['days' => 1]],
        deniedRoles: ['editor', 'reader'],
    ),
    'reports.links.destroy' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => isolationReportLink($s->tenantB->id)],
        own: fn (Scenario $s) => ['params' => isolationReportLink($s->tenantA->id)],
        deniedRoles: ['editor', 'reader'],
    ),
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
    'reports.show' => RouteAccess::tenant(
        foreign: function (Scenario $s) {
            $id = app(TenantContext::class)->run($s->tenantB->id, fn () => Report::factory()->create()->id);

            return ['params' => ['report' => $id]];
        },
        own: function (Scenario $s) {
            $id = app(TenantContext::class)->run($s->tenantA->id, fn () => Report::factory()->create()->id);

            return ['params' => ['report' => $id]];
        },
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
    'brands.competitors.destroy' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => isolationCompetitor($s->tenantB->id)],
        own: fn (Scenario $s) => ['params' => isolationCompetitor($s->tenantA->id)],
        deniedRoles: ['reader'],
    ),
    // T49: the fact page. The fact must belong to the brand in the path (BrandFactPageTest checks a fact of another brand).
    'brands.facts.index' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)]],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)]],
    ),
    'brands.facts.store' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)], 'data' => ['text' => 'Grunnlagt i 1998.']],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)], 'data' => ['text' => 'Grunnlagt i 1998.']],
        deniedRoles: ['editor', 'reader'],
    ),
    'brands.facts.update' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => isolationFact($s->tenantB->id), 'data' => ['is_active' => false]],
        own: fn (Scenario $s) => ['params' => isolationFact($s->tenantA->id), 'data' => ['is_active' => false]],
        deniedRoles: ['editor', 'reader'],
    ),
    'brands.facts.destroy' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => isolationFact($s->tenantB->id)],
        own: fn (Scenario $s) => ['params' => isolationFact($s->tenantA->id)],
        deniedRoles: ['editor', 'reader'],
    ),
    'brands.sources' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)]],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)]],
    ),
    'brands.crawlers' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)]],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)]],
    ),
    'brands.actions.index' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantB->id)]],
        own: fn (Scenario $s) => ['params' => ['brand' => isolationBrandId($s->tenantA->id)]],
    ),
    'brands.actions.store' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => [
            'params' => ['brand' => isolationBrandId($s->tenantB->id)],
            'data' => ['title' => 'Eget tiltak'],
        ],
        own: fn (Scenario $s) => [
            'params' => ['brand' => isolationBrandId($s->tenantA->id)],
            'data' => ['title' => 'Eget tiltak'],
        ],
        deniedRoles: ['reader'],
    ),
    'brands.actions.show' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => ['params' => array_diff_key(isolationAction($s->tenantB->id), ['step' => 0])],
        own: fn (Scenario $s) => ['params' => array_diff_key(isolationAction($s->tenantA->id), ['step' => 0])],
    ),
    'brands.actions.update' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => [
            'params' => array_diff_key(isolationAction($s->tenantB->id), ['step' => 0]),
            'data' => ['status' => 'planned'],
        ],
        own: fn (Scenario $s) => [
            'params' => array_diff_key(isolationAction($s->tenantA->id), ['step' => 0]),
            'data' => ['status' => 'planned'],
        ],
        deniedRoles: ['reader'],
    ),
    'brands.actions.steps' => RouteAccess::tenant(
        foreign: fn (Scenario $s) => [
            'params' => isolationAction($s->tenantB->id),
            'data' => ['done' => true],
        ],
        own: fn (Scenario $s) => [
            'params' => isolationAction($s->tenantA->id),
            'data' => ['done' => true],
        ],
        deniedRoles: ['reader'],
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
