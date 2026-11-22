<?php

namespace App\Http\Controllers;

use App\Dashboard\BrandPage;
use App\Integrations\BrandIntegrations;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup › Integrations (docs/ux-arbeidsflate.md 5.8, U24).
 * One card per type. The encrypted config is never sent to the browser.
 */
class SetupIntegrationController extends Controller
{
    public function __invoke(Brand $brand, BrandIntegrations $integrations): Response
    {
        BrandPage::authorizeView($brand);
        $brand->load('integrations');
        $tenant = Team::query()->findOrFail((int) app(TenantContext::class)->require());
        $see = Gate::allows('viewAny', Integration::class);
        $byType = $brand->integrations->keyBy('type');

        return Inertia::render('Brands/Setup/Integrations', [
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'tenantName' => $tenant->name,
            'integrations' => $see ? array_map(function (string $type) use ($byType): array {
                $integration = $byType->get($type);

                return [
                    'type' => $type,
                    'connected' => $integration instanceof Integration,
                    'status' => $integration instanceof Integration ? $integration->status : null,
                    'last_sync_at' => $integration?->last_sync_at?->toIso8601String(),
                    'failing_since' => $integration?->last_error_at?->toIso8601String(),
                ];
            }, ['clickhouse_logs', 'ga4']) : [],
            'clickhouseEndpoints' => $see ? $integrations->endpoints() : [],
            'logUploadUrl' => $see ? route('bot-log-uploads.index', ['team' => current_tenant_id()]) : null,
            'canManage' => $see && Gate::allows('update', $brand),
        ]);
    }
}
