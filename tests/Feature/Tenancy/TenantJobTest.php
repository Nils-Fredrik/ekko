<?php

use App\Jobs\Middleware\ApplyTenantRls;
use App\Jobs\Middleware\SetTenantContext;
use App\Models\Team;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMismatchException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreateTenantRecordJob;
use Tests\Fixtures\TenantRecord;

beforeEach(function () {
    TenantRecord::createTable();
    $this->tenantA = Team::factory()->tenant()->create();
    $this->tenantB = Team::factory()->tenant()->create();
});

test('a tenant job runs in its own tenant and writes there', function () {
    // The sync queue serializes the job and runs it through the job middleware, like a real worker.
    dispatch_sync(new CreateTenantRecordJob($this->tenantA->id, 'from job'));

    // The record has no explicit tenant_id, so it got it from the context the middleware set.
    expect(DB::table('test_tenant_records')->pluck('tenant_id')->all())->toBe([$this->tenantA->id])
        ->and(app(TenantContext::class)->check())->toBeFalse();
});

test('a tenant job uses its own tenant, not the tenant of the code that dispatched it', function () {
    app(TenantContext::class)->set($this->tenantB->id);

    dispatch_sync(new CreateTenantRecordJob($this->tenantA->id, 'from job'));

    expect(DB::table('test_tenant_records')->pluck('tenant_id')->all())->toBe([$this->tenantA->id])
        ->and(current_tenant_id())->toBe($this->tenantB->id);
});

test('a tenant job cannot write to another tenant', function () {
    expect(fn () => dispatch_sync(new CreateTenantRecordJob($this->tenantA->id, 'x', $this->tenantB->id)))
        ->toThrow(TenantMismatchException::class);

    expect(DB::table('test_tenant_records')->count())->toBe(0)
        ->and(app(TenantContext::class)->check())->toBeFalse();
});

test('the tenant is a constructor parameter and is kept when the job is serialized', function () {
    $job = unserialize(serialize(new CreateTenantRecordJob($this->tenantA->id, 'x')));

    expect($job->tenantId())->toBe($this->tenantA->id)
        ->and($job->middleware())->toHaveCount(2)
        ->and($job->middleware()[0])->toBeInstanceOf(SetTenantContext::class)
        ->and($job->middleware()[1])->toBeInstanceOf(ApplyTenantRls::class);
});

test('the job middleware refuses a job without a tenant', function () {
    (new SetTenantContext)->handle(new stdClass, fn () => null);
})->throws(LogicException::class);
