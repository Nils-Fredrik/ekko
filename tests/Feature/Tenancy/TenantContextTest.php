<?php

use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContext;

test('current_tenant_id throws when no tenant is set', function () {
    current_tenant_id();
})->throws(MissingTenantContextException::class);

test('current_tenant_id returns the tenant in the context', function () {
    app(TenantContext::class)->set(42);

    expect(current_tenant_id())->toBe(42)
        ->and(app(TenantContext::class)->check())->toBeTrue();
});

test('run sets the tenant and restores the previous context, also when the callback throws', function () {
    $context = app(TenantContext::class);
    $context->set(1);

    expect($context->run(2, fn () => current_tenant_id()))->toBe(2)
        ->and($context->id())->toBe(1);

    expect(fn () => $context->run(3, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);
    expect($context->id())->toBe(1);
});

test('the context is reset between requests and queued jobs', function () {
    app(TenantContext::class)->set(7);

    // The queue worker and the HTTP kernel call forgetScopedInstances() between jobs and requests.
    app()->forgetScopedInstances();

    expect(app(TenantContext::class)->check())->toBeFalse();
});
