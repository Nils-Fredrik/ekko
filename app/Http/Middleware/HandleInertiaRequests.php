<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Onboarding\Analysis;
use App\Tenancy\ActingAs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
            'locales' => function (): array {
                $locales = config('ekko.locales');
                if (! is_array($locales)) {
                    return [];
                }

                $shared = [];
                foreach ($locales as $code) {
                    if (! is_string($code)) {
                        continue;
                    }
                    $shared[] = ['code' => $code, 'label' => __("app.locales.{$code}")];
                }

                return $shared;
            },
            // Providers with credentials; the login page shows a button for each.
            'ssoProviders' => fn () => array_values(array_filter(
                config('ekko.sso_providers'),
                fn (string $provider) => filled(config("services.{$provider}.client_id")) && filled(config("services.{$provider}.client_secret")),
            )),
            'actingAs' => fn () => $this->actingAs($request),
            'canOnboard' => function () use ($request): bool {
                $user = $request->user();

                return $user instanceof User && Gate::forUser($user)->allows('createBrandAnalysis', Analysis::class);
            },
        ];
    }

    /**
     * Shown as a banner while a platform admin acts as a tenant (docs/spec.md 16).
     *
     * @return array{tenant: string, reason: string, started_at: string}|null
     */
    private function actingAs(Request $request): ?array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }

        $actingAs = app(ActingAs::class);
        $tenantId = $actingAs->tenantIdFor($user);
        $state = $actingAs->state();
        $tenant = $tenantId === null ? null : Team::query()->find($tenantId);

        if ($tenant === null || $state === null) {
            return null;
        }

        return [
            'tenant' => $tenant->name,
            'reason' => $state['reason'],
            'started_at' => $state['started_at'],
        ];
    }
}
