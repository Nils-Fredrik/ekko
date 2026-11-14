<?php

use App\Enums\TenantPermission as P;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\ActingAs;
use App\Tenancy\TenantAccess;
use Illuminate\Support\Facades\Gate;

// docs/spec.md 5.2: the role table. The expected rights are written out here, not derived from
// TenantRole, so a change to the roles has to be made deliberately in both places.

const ALL_TENANT_PERMISSIONS = [
    P::ViewData, P::ManagePrompts, P::ManageCompetitors, P::RunChecks, P::ManageBrands, P::ManageMarkets,
    P::ManageAlerts, P::ManageReports, P::ViewUsage, P::ManageMembers, P::UpdateTenant,
];

function memberWithRole(Team $tenant, string $role): User
{
    $user = User::factory()->create();
    $tenant->users()->attach($user, ['role' => $role]);

    return $user;
}

function access(): TenantAccess
{
    $access = app(TenantAccess::class);
    $access->flush();

    return $access;
}

beforeEach(function () {
    $this->tenant = Team::factory()->tenant()->create();
});

test('tenant admin: invite and remove users, edit brands, markets, prompts and competitors, alerts, report recipients, usage', function () {
    $admin = memberWithRole($this->tenant, 'admin');

    foreach (ALL_TENANT_PERMISSIONS as $permission) {
        expect(access()->can($admin, $this->tenant, $permission))->toBeTrue("admin should have {$permission->value}");
    }
});

test('the tenant owner is a tenant admin', function () {
    foreach (ALL_TENANT_PERMISSIONS as $permission) {
        expect(access()->can($this->tenant->owner, $this->tenant, $permission))->toBeTrue();
    }
});

test('editor: edit prompts and competitors and run manual checks, nothing else', function () {
    $editor = memberWithRole($this->tenant, 'editor');
    $allowed = [P::ViewData, P::ManagePrompts, P::ManageCompetitors, P::RunChecks];

    foreach (ALL_TENANT_PERMISSIONS as $permission) {
        expect(access()->can($editor, $this->tenant, $permission))
            ->toBe(in_array($permission, $allowed, true), "editor and {$permission->value}");
    }
});

test('reader: see dashboard, answers, sources and reports only', function () {
    $reader = memberWithRole($this->tenant, 'reader');

    foreach (ALL_TENANT_PERMISSIONS as $permission) {
        expect(access()->can($reader, $this->tenant, $permission))
            ->toBe($permission === P::ViewData, "reader and {$permission->value}");
    }
});

test('no role in another tenant', function (string $role) {
    $member = memberWithRole($this->tenant, $role);
    $other = Team::factory()->tenant()->create();

    expect(access()->canAccess($member, $other))->toBeFalse();
    foreach (ALL_TENANT_PERMISSIONS as $permission) {
        expect(access()->can($member, $other, $permission))->toBeFalse();
    }
})->with(['admin', 'partner_advisor', 'editor', 'reader']);

test('partner advisor: tenant admin in the partner tenant and in every client tenant', function () {
    $partner = $this->tenant;
    $clientA = Team::factory()->clientOf($partner)->create();
    $clientB = Team::factory()->clientOf($partner)->create();
    $advisor = memberWithRole($partner, 'partner_advisor');

    foreach ([$partner, $clientA, $clientB] as $tenant) {
        foreach (ALL_TENANT_PERMISSIONS as $permission) {
            expect(access()->can($advisor, $tenant, $permission))->toBeTrue("{$tenant->name}: {$permission->value}");
        }
    }
});

test('partner advisor: cannot delete client tenants or change their plan', function () {
    $client = Team::factory()->clientOf($this->tenant)->create();
    $advisor = memberWithRole($this->tenant, 'partner_advisor');

    expect(Gate::forUser($advisor)->allows('delete', $client))->toBeFalse()
        ->and(Gate::forUser($advisor)->allows('changePlan', $client))->toBeFalse()
        ->and(Gate::forUser($advisor)->allows('update', $client))->toBeTrue();
});

test('partner advisor: no access to tenants of other partners or to unrelated tenants', function () {
    $otherPartner = Team::factory()->tenant()->create();
    $otherClient = Team::factory()->clientOf($otherPartner)->create();
    $advisor = memberWithRole($this->tenant, 'partner_advisor');

    expect(access()->canAccess($advisor, $otherPartner))->toBeFalse()
        ->and(access()->canAccess($advisor, $otherClient))->toBeFalse();
});

test('only the partner advisor role reaches client tenants, not other roles in the partner tenant', function (string $role) {
    $client = Team::factory()->clientOf($this->tenant)->create();
    $member = memberWithRole($this->tenant, $role);

    expect(access()->canAccess($member, $client))->toBeFalse();
})->with(['admin', 'editor', 'reader']);

test('a member of a client tenant gets no access to the partner tenant', function () {
    $client = Team::factory()->clientOf($this->tenant)->create();
    $clientAdmin = memberWithRole($client, 'admin');

    expect(access()->canAccess($clientAdmin, $this->tenant))->toBeFalse();
});

test('platform admin: create, change and close tenants, set the plan, act as tenant', function () {
    $platformAdmin = User::factory()->platformAdmin()->create();
    $gate = Gate::forUser($platformAdmin);

    expect($gate->allows('create', Team::class))->toBeTrue()
        ->and($gate->allows('delete', $this->tenant))->toBeTrue()
        ->and($gate->allows('changePlan', $this->tenant))->toBeTrue()
        ->and($gate->allows('actAs', $this->tenant))->toBeTrue()
        ->and($gate->allows('viewAny', Plan::class))->toBeTrue()
        ->and($gate->allows('update', Plan::factory()->create()))->toBeTrue();
});

test('platform admin: tenant data only while acting as the tenant', function () {
    $platformAdmin = User::factory()->platformAdmin()->create();

    expect(access()->canAccess($platformAdmin, $this->tenant))->toBeFalse();

    app(ActingAs::class)->start($platformAdmin, $this->tenant, 'Support case 123');

    foreach (ALL_TENANT_PERMISSIONS as $permission) {
        expect(access()->can($platformAdmin, $this->tenant, $permission))->toBeTrue();
    }
});

test('the platform-only rights are denied to every tenant role', function (string $role) {
    $member = memberWithRole($this->tenant, $role);
    $gate = Gate::forUser($member);

    expect($gate->allows('create', Team::class))->toBeFalse()
        ->and($gate->allows('delete', $this->tenant))->toBeFalse()
        ->and($gate->allows('changePlan', $this->tenant))->toBeFalse()
        ->and($gate->allows('actAs', $this->tenant))->toBeFalse()
        ->and($gate->allows('viewAny', Plan::class))->toBeFalse();
})->with(['admin', 'partner_advisor', 'editor', 'reader']);

test('a closed tenant is closed for its members and partner, but reachable when acting as it', function () {
    $client = Team::factory()->clientOf($this->tenant)->status(TenantStatus::Closed)->create();
    $member = memberWithRole($client, 'admin');
    $advisor = memberWithRole($this->tenant, 'partner_advisor');
    $platformAdmin = User::factory()->platformAdmin()->create();

    expect(access()->canAccess($member, $client))->toBeFalse()
        ->and(access()->canAccess($advisor, $client))->toBeFalse();

    app(ActingAs::class)->start($platformAdmin, $client, 'Export before deletion');
    expect(access()->canAccess($platformAdmin, $client))->toBeTrue();
});

test('the team page permissions follow the role table', function (string $role, array $expected) {
    $member = memberWithRole($this->tenant, $role);
    $gate = Gate::forUser($member);

    expect([
        'view' => $gate->allows('view', $this->tenant),
        'update' => $gate->allows('update', $this->tenant),
        'add' => $gate->allows('addTeamMember', $this->tenant),
        'remove' => $gate->allows('removeTeamMember', $this->tenant),
        'usage' => $gate->allows('viewUsage', $this->tenant),
    ])->toBe($expected);
})->with([
    'admin' => ['admin', ['view' => true, 'update' => true, 'add' => true, 'remove' => true, 'usage' => true]],
    'editor' => ['editor', ['view' => true, 'update' => false, 'add' => false, 'remove' => false, 'usage' => false]],
    'reader' => ['reader', ['view' => true, 'update' => false, 'add' => false, 'remove' => false, 'usage' => false]],
]);
