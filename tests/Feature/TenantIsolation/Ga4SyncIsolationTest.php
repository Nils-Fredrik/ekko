<?php

use App\Jobs\SyncGa4Integration;
use App\Models\AiReferralDaily;
use App\Models\Alert;
use App\Models\Integration;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;

test('a GA4 job cannot access an integration from another tenant', function () {
    $tenantA = Team::factory()->tenant()->create();
    $tenantB = Team::factory()->tenant()->create();
    $context = app(TenantContext::class);
    $rls = app(TenantRls::class);

    $integration = $context->run($tenantB->id, fn () => Integration::factory()->create());

    Http::preventStrayRequests();
    Http::fake();
    dispatch_sync(new SyncGa4Integration($tenantA->id, $integration->id));
    Http::assertNothingSent();

    $context->run($tenantA->id, fn () => $rls->run([$tenantA->id], function (): void {
        expect(AiReferralDaily::query()->count())->toBe(0)
            ->and(Alert::query()->count())->toBe(0);
    }));
    $context->run($tenantB->id, fn () => $rls->run([$tenantB->id], function () use ($integration): void {
        expect($integration->fresh()->status)->toBe('pending')
            ->and($integration->fresh()->last_failed_on)->toBeNull()
            ->and(AiReferralDaily::query()->count())->toBe(0)
            ->and(Alert::query()->count())->toBe(0);
    }));
});
