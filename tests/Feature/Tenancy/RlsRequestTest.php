<?php

use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\TenantRecord;

// ApplyTenantRls on web routes: every request runs under row-level security.

beforeEach(function () {
    TenantRecord::createTable();
    $this->tenantA = Team::factory()->tenant()->create();
    $this->tenantB = Team::factory()->tenant()->create();

    foreach ([[$this->tenantA, 'a1'], [$this->tenantB, 'b1']] as [$tenant, $name]) {
        DB::table('test_tenant_records')->insert([
            'tenant_id' => $tenant->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    Route::middleware('web')->group(function () {
        Route::get('/_test/rls', fn () => [
            'unscoped' => TenantRecord::withoutGlobalScope(TenantScope::class)->pluck('name')->all(),
            'raw' => DB::table('test_tenant_records')->pluck('name')->all(),
            'role' => DB::selectOne('SELECT current_user AS role')->role,
        ]);
        Route::post('/_test/rls/fail', function () {
            DB::table('test_tenant_records')->insert([
                'tenant_id' => app(TenantContext::class)->require(), 'name' => 'half-done',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            abort(500);
        })->withoutMiddleware(ValidateCsrfToken::class);
        Route::get('/_test/rls/records/{record}', fn (TenantRecord $record) => ['name' => $record->name]);
    });
});

function memberOf(Team $tenant): User
{
    $user = User::factory()->create();
    $tenant->users()->attach($user, ['role' => 'editor']);
    $user->switchTeam($tenant);

    return $user;
}

test('a request only sees its tenant, even without the global scope', function () {
    $this->actingAs(memberOf($this->tenantA))
        ->get('/_test/rls')
        ->assertExactJson(['unscoped' => ['a1'], 'raw' => ['a1'], 'role' => 'ekko_app']);
});

test('a guest request sees no tenant-owned rows', function () {
    $this->get('/_test/rls')->assertExactJson(['unscoped' => [], 'raw' => [], 'role' => 'ekko_app']);
});

test('a server error rolls back the request transaction', function () {
    $this->actingAs(memberOf($this->tenantA))->post('/_test/rls/fail')->assertStatus(500);

    expect(DB::table('test_tenant_records')->where('name', 'half-done')->exists())->toBeFalse();
});

test('route model binding runs under the tenant and row-level security', function () {
    $own = DB::table('test_tenant_records')->where('name', 'a1')->value('id');
    $other = DB::table('test_tenant_records')->where('name', 'b1')->value('id');
    $user = memberOf($this->tenantA);

    $this->actingAs($user)->get("/_test/rls/records/{$own}")->assertExactJson(['name' => 'a1']);
    $this->actingAs($user)->get("/_test/rls/records/{$other}")->assertNotFound();
});
