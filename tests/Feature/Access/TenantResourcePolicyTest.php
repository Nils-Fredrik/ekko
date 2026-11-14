<?php

use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\TenantRecord;
use Tests\Fixtures\TenantRecordPolicy;

// The base policy for tenant-owned resources, with a fixture mapped like prompts (ManagePrompts).

beforeEach(function () {
    TenantRecord::createTable();
    Gate::policy(TenantRecord::class, TenantRecordPolicy::class);

    $this->tenant = Team::factory()->tenant()->create();
    $this->other = Team::factory()->tenant()->create();
    app(TenantContext::class)->set($this->tenant->id);

    $this->record = TenantRecord::create(['name' => 'own']);
    $otherId = DB::table('test_tenant_records')->insertGetId([
        'tenant_id' => $this->other->id, 'name' => 'other', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->otherRecord = (new TenantRecord)->forceFill(['id' => $otherId, 'tenant_id' => $this->other->id, 'name' => 'other']);
});

function userWithRole(Team $tenant, string $role): User
{
    $user = User::factory()->create();
    $tenant->users()->attach($user, ['role' => $role]);

    return $user;
}

test('the role decides what may be done with a resource', function (string $role, array $expected) {
    $gate = Gate::forUser(userWithRole($this->tenant, $role));

    expect([
        'viewAny' => $gate->allows('viewAny', TenantRecord::class),
        'view' => $gate->allows('view', $this->record),
        'create' => $gate->allows('create', TenantRecord::class),
        'update' => $gate->allows('update', $this->record),
        'delete' => $gate->allows('delete', $this->record),
    ])->toBe($expected);
})->with([
    'admin' => ['admin', ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'delete' => true]],
    'editor' => ['editor', ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'delete' => true]],
    'reader' => ['reader', ['viewAny' => true, 'view' => true, 'create' => false, 'update' => false, 'delete' => false]],
]);

test('a resource in another tenant is denied, whatever the role', function (string $role) {
    $gate = Gate::forUser(userWithRole($this->tenant, $role));

    expect($gate->allows('view', $this->otherRecord))->toBeFalse()
        ->and($gate->allows('update', $this->otherRecord))->toBeFalse()
        ->and($gate->allows('delete', $this->otherRecord))->toBeFalse();
})->with(['admin', 'partner_advisor', 'editor', 'reader']);

test('without a tenant in the context viewAny and create are denied', function () {
    $admin = userWithRole($this->tenant, 'admin');
    app(TenantContext::class)->forget();

    expect(Gate::forUser($admin)->allows('viewAny', TenantRecord::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('create', TenantRecord::class))->toBeFalse();
});
