<?php

use App\Models;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

// Board task:44: every tenant-owned model from T08 has a policy, tested per role against the role
// table in docs/spec.md 5.2. The expectations are written out, not derived from TenantRole, so a
// change to the rights has to be made deliberately in both places (as in RoleMatrixTest).

const POLICY_EVERY_ROLE = ['admin', 'partner_advisor', 'editor', 'reader'];
const POLICY_ADMINS = ['admin', 'partner_advisor'];
const POLICY_EDITORS = ['admin', 'partner_advisor', 'editor'];
const POLICY_NO_ROLE = [];

/**
 * Who may read (viewAny, view), create, and change (update, delete) each tenant-owned model.
 *
 * @return array<class-string<Model>, array{read: list<string>, create: list<string>, change: list<string>}>
 */
function tenantModelRights(): array
{
    $edit = fn (array $read, array $write) => ['read' => $read, 'create' => $write, 'change' => $write];
    $system = fn (array $read) => ['read' => $read, 'create' => POLICY_NO_ROLE, 'change' => POLICY_NO_ROLE];

    return [
        // Configuration (6.2): brands and markets for the admin, prompts and competitors also for the editor.
        Models\Brand::class => $edit(POLICY_EVERY_ROLE, POLICY_ADMINS),
        Models\BrandAlias::class => $edit(POLICY_EVERY_ROLE, POLICY_ADMINS),
        Models\BrandDomain::class => $edit(POLICY_EVERY_ROLE, POLICY_ADMINS),
        Models\Market::class => $edit(POLICY_EVERY_ROLE, POLICY_ADMINS),
        Models\MarketEngine::class => $edit(POLICY_EVERY_ROLE, POLICY_ADMINS),
        Models\Competitor::class => $edit(POLICY_EVERY_ROLE, POLICY_EDITORS),
        Models\CompetitorAlias::class => $edit(POLICY_EVERY_ROLE, POLICY_EDITORS),
        Models\CompetitorDomain::class => $edit(POLICY_EVERY_ROLE, POLICY_EDITORS),
        Models\Prompt::class => $edit(POLICY_EVERY_ROLE, POLICY_EDITORS),
        Models\Topic::class => $edit(POLICY_EVERY_ROLE, POLICY_EDITORS),

        // Alerts and reports (6.5): set by the admin, seen by everyone.
        Models\AlertRule::class => $edit(POLICY_EVERY_ROLE, POLICY_ADMINS),
        // Alerts and reports are made by the system; the admin acknowledges or removes them.
        Models\Alert::class => ['read' => POLICY_EVERY_ROLE, 'create' => POLICY_NO_ROLE, 'change' => POLICY_ADMINS],
        Models\AlertEvaluation::class => $system(POLICY_ADMINS),
        Models\ReportSchedule::class => $edit(POLICY_EVERY_ROLE, POLICY_ADMINS),
        Models\Report::class => ['read' => POLICY_EVERY_ROLE, 'create' => POLICY_NO_ROLE, 'change' => POLICY_ADMINS],

        // Integrations hold credentials: admin only, also for reading.
        Models\Integration::class => $edit(POLICY_ADMINS, POLICY_ADMINS),
        Models\TenantApiKey::class => $edit(POLICY_ADMINS, POLICY_ADMINS),

        // Collection and results (6.3, 6.4): written by the system, read by everyone. A manual
        // check (admin and editor) starts a run batch.
        Models\RunBatch::class => ['read' => POLICY_EVERY_ROLE, 'create' => POLICY_EDITORS, 'change' => POLICY_NO_ROLE],
        Models\Run::class => $system(POLICY_EVERY_ROLE),
        Models\Response::class => $system(POLICY_EVERY_ROLE),
        Models\Mention::class => $system(POLICY_EVERY_ROLE),
        Models\Citation::class => $system(POLICY_EVERY_ROLE),
        Models\AggDaily::class => $system(POLICY_EVERY_ROLE),
        Models\AggSourceDaily::class => $system(POLICY_EVERY_ROLE),
        Models\ModelChange::class => $system(POLICY_EVERY_ROLE),
        Models\BotHitDaily::class => $system(POLICY_EVERY_ROLE),
        Models\AiReferralDaily::class => $system(POLICY_EVERY_ROLE),
        Models\ExtractionJob::class => $system(POLICY_NO_ROLE),

        // Usage (6.5): "se forbruk" is the admin's.
        Models\UsageEvent::class => $system(POLICY_ADMINS),
        Models\UsageMonthly::class => $system(POLICY_ADMINS),

        // The revision log: written by the system, read by the admin.
        Models\Activity::class => $system(POLICY_ADMINS),
    ];
}

/**
 * @return list<class-string<Model>>
 */
function tenantOwnedModels(): array
{
    $models = [];
    foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (class_exists($class) && in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $models[] = $class;
        }
    }
    sort($models);

    return $models;
}

function policyMember(Team $tenant, string $role): User
{
    $user = User::factory()->create();
    $tenant->users()->attach($user, ['role' => $role]);

    return $user;
}

/**
 * A model instance in the tenant, without a database row: the policy only reads tenant_id.
 *
 * @param  class-string<Model>  $class
 */
function policyModelIn(string $class, Team $tenant): Model
{
    return (new $class)->forceFill(['id' => 1, 'tenant_id' => $tenant->id]);
}

beforeEach(function () {
    $this->tenant = Team::factory()->tenant()->create();
    app(TenantContext::class)->set($this->tenant->id);
});

test('every tenant-owned model has a policy and an entry in this test', function () {
    $missingPolicy = array_values(array_filter(tenantOwnedModels(), fn (string $class) => Gate::getPolicyFor($class) === null));

    expect($missingPolicy)->toBe([], 'Tenant-owned models without a policy: '.implode(', ', $missingPolicy))
        ->and(array_keys(tenantModelRights()))->toEqualCanonicalizing(tenantOwnedModels());
});

test('the role decides what may be done with each tenant-owned model', function (string $role) {
    $gate = Gate::forUser(policyMember($this->tenant, $role));
    $wrong = [];

    foreach (tenantModelRights() as $class => $rights) {
        $model = policyModelIn($class, $this->tenant);
        $actual = [
            'viewAny' => $gate->allows('viewAny', $class),
            'view' => $gate->allows('view', $model),
            'create' => $gate->allows('create', $class),
            'update' => $gate->allows('update', $model),
            'delete' => $gate->allows('delete', $model),
        ];
        $expected = [
            'viewAny' => in_array($role, $rights['read'], true),
            'view' => in_array($role, $rights['read'], true),
            'create' => in_array($role, $rights['create'], true),
            'update' => in_array($role, $rights['change'], true),
            'delete' => in_array($role, $rights['change'], true),
        ];

        foreach ($expected as $ability => $allowed) {
            if ($actual[$ability] !== $allowed) {
                $wrong[] = class_basename($class).".{$ability} should be ".($allowed ? 'allowed' : 'denied');
            }
        }
    }

    expect($wrong)->toBe([]);
})->with(POLICY_EVERY_ROLE);

test('a model in another tenant is denied for every role', function (string $role) {
    $gate = Gate::forUser(policyMember($this->tenant, $role));
    $other = Team::factory()->tenant()->create();
    $allowed = [];

    foreach (array_keys(tenantModelRights()) as $class) {
        $model = policyModelIn($class, $other);
        foreach (['view', 'update', 'delete'] as $ability) {
            if ($gate->allows($ability, $model)) {
                $allowed[] = class_basename($class).".{$ability}";
            }
        }
    }

    expect($allowed)->toBe([]);
})->with(POLICY_EVERY_ROLE);

test('a partner advisor has the admin rights in a client tenant', function () {
    $partner = Team::factory()->tenant()->create();
    $client = Team::factory()->tenant()->create(['partner_team_id' => $partner->id]);
    $gate = Gate::forUser(policyMember($partner, 'partner_advisor'));
    app(TenantContext::class)->set($client->id);

    foreach (tenantModelRights() as $class => $rights) {
        $model = policyModelIn($class, $client);

        expect($gate->allows('view', $model))->toBe(in_array('admin', $rights['read'], true), class_basename($class))
            ->and($gate->allows('create', $class))->toBe(in_array('admin', $rights['create'], true), class_basename($class))
            ->and($gate->allows('update', $model))->toBe(in_array('admin', $rights['change'], true), class_basename($class));
    }
});

test('a user without a role in the tenant gets nothing', function () {
    $gate = Gate::forUser(User::factory()->create());

    foreach (array_keys(tenantModelRights()) as $class) {
        expect($gate->allows('viewAny', $class))->toBeFalse(class_basename($class))
            ->and($gate->allows('view', policyModelIn($class, $this->tenant)))->toBeFalse(class_basename($class));
    }
});
