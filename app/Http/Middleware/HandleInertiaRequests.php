<?php

namespace App\Http\Middleware;

use App\Engines\Credentials\ApiKeyProvider;
use App\Models\Team;
use App\Models\TenantApiKey;
use App\Models\UsageEvent;
use App\Models\User;
use App\Onboarding\Analysis;
use App\Tenancy\ActingAs;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use App\Workspace\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
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
            // The active tenant's time zone, for resources/js/format.js (docs/ux-arbeidsflate.md 4.2).
            'tenant' => fn (): ?array => $this->tenant($request),
            // The shell's brand, market, counts and usage (docs/ux-arbeidsflate.md 4.1). Deferred in a group
            // of its own, so the page renders first and the sidebar does not wait for the charts. Guests have
            // no shell and get null without a second request.
            'workspace' => $request->user() instanceof User
                ? Inertia::defer(fn (): ?array => $this->workspace($request), 'workspace')
                : null,
            // Providers with credentials; the login page shows a button for each.
            'ssoProviders' => fn (): array => $this->configuredSsoProviders(),
            // Providers the signed-in user can confirm with instead of a password (T07-F2).
            'ssoConfirmProviders' => function () use ($request): array {
                $user = $request->user();
                if (! $user instanceof User) {
                    return [];
                }
                $configured = $this->configuredSsoProviders();
                if ($configured === []) {
                    return [];
                }

                return array_values(array_intersect($configured, $user->socialAccounts()->pluck('provider')->all()));
            },
            'actingAs' => fn () => $this->actingAs($request),
            'canOnboard' => function () use ($request): bool {
                $user = $request->user();

                return $user instanceof User && Gate::forUser($user)->allows('createBrandAnalysis', Analysis::class);
            },
            'canViewUsage' => function () use ($request): bool {
                $user = $request->user();

                return $user instanceof User && Gate::forUser($user)->allows('viewAny', UsageEvent::class);
            },
            'canViewPartner' => function () use ($request): bool {
                $user = $request->user();
                $team = $user?->currentTeam;
                if (! $user instanceof User || ! $team instanceof Team) {
                    return false;
                }

                return app(TenantAccess::class)->advisesPartner($user, $team);
            },
            'pitchProjects' => function () use ($request): array {
                if (! $request->routeIs('partner.index')) {
                    return [];
                }
                $user = $request->user();
                $partner = $user?->currentTeam;
                if (! $user instanceof User || ! $partner instanceof Team || ! app(TenantAccess::class)->advisesPartner($user, $partner)) {
                    return [];
                }

                return Team::query()->where('partner_team_id', $partner->id)->where('kind', 'pitch')
                    ->where('status', '!=', 'closed')->pluck('id')->map(fn ($id): int => (int) $id)->all();
            },
            // Only the team page. Secrets stay on the server: the webhook and API keys are present or absent.
            'teamProfile' => fn () => $this->teamProfile($request),
        ];
    }

    /**
     * @return array{timezone: string}|null
     */
    private function tenant(Request $request): ?array
    {
        $tenantId = app(TenantContext::class)->id();
        if ($tenantId === null) {
            return null;
        }
        // Usually the user's current team, which the other shared props have already loaded.
        $current = $request->user()?->currentTeam;
        $timezone = $current instanceof Team && $current->id === $tenantId
            ? $current->timezone
            : Team::query()->whereKey($tenantId)->value('timezone');

        return ['timezone' => is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Oslo'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function workspace(Request $request): ?array
    {
        $tenantId = app(TenantContext::class)->id();

        return $tenantId === null ? null : app(Workspace::class)->payload($request, $tenantId);
    }

    /**
     * @return array{can_update: bool, slack_configured: bool, primary: string, byok_enabled: bool, byok_budget?: string|null, keys?: list<array{provider: string, configured: bool, verified_at: string|null}>}|null
     */
    private function teamProfile(Request $request): ?array
    {
        if (! $request->routeIs('teams.show')) {
            return null;
        }

        $team = $request->route('team');
        if (! $team instanceof Team) {
            $team = Team::query()->find($team);
        }
        $user = $request->user();
        $tenantId = app(TenantContext::class)->id();
        if (! $team instanceof Team || ! $user instanceof User || $tenantId === null || $team->id !== $tenantId) {
            return null;
        }
        if (! Gate::forUser($user)->allows('view', $team)) {
            return null;
        }

        $primary = $team->branding['primary'] ?? null;
        $profile = [
            'can_update' => Gate::forUser($user)->allows('update', $team),
            'slack_configured' => filled($team->getAttributes()['slack_webhook_url'] ?? null),
            'primary' => is_string($primary) ? $primary : '',
            'has_logo' => is_string($team->branding['logo'] ?? null),
            'byok_enabled' => $team->byok_enabled,
        ];

        // Key status is tenant administration. The budget is usage. Readers and editors have neither.
        if (Gate::forUser($user)->allows('viewAny', TenantApiKey::class)) {
            $stored = TenantApiKey::query()->get(['provider', 'last_verified_at'])->keyBy(
                fn (TenantApiKey $key): string => $key->provider->value,
            );
            $profile['keys'] = array_map(function (ApiKeyProvider $provider) use ($stored): array {
                $key = $stored->get($provider->value);

                return [
                    'provider' => $provider->value,
                    'configured' => $key instanceof TenantApiKey,
                    'verified_at' => $key instanceof TenantApiKey ? $key->last_verified_at?->toIso8601String() : null,
                ];
            }, ApiKeyProvider::cases());
        }
        if (Gate::forUser($user)->allows('viewUsage', $team)) {
            $profile['byok_budget'] = $team->monthly_byok_budget_usd;
        }

        return $profile;
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

    /**
     * Providers with credentials, in the order of ekko.sso_providers.
     *
     * @return list<string>
     */
    private function configuredSsoProviders(): array
    {
        return array_values(array_filter(
            config('ekko.sso_providers'),
            fn (string $provider) => filled(config("services.{$provider}.client_id")) && filled(config("services.{$provider}.client_secret")),
        ));
    }
}
