<?php

namespace App\Http\Controllers;

use App\Alerts\SlackNotifier;
use App\Engines\Credentials\ApiKeyProvider;
use App\Engines\Credentials\TenantApiKeys;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\AuditLog;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Slack webhook, report profile and own API keys on the team page (docs/spec.md 14.2, T28).
 * Stored secrets are never returned, and the audit entry records that they changed without the value.
 */
class TeamSettingsController extends Controller
{
    public function updateSlack(Request $request, Team $team, SlackNotifier $slack, AuditLog $audit): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        $data = $request->validate([
            'webhook_url' => ['nullable', 'string', 'max:500'],
            'clear' => ['sometimes', 'boolean'],
        ], [], [
            'webhook_url' => __('app.workspace.slack_url'),
        ]);
        $clear = ($data['clear'] ?? false) === true;
        $url = is_string($data['webhook_url'] ?? null) ? trim($data['webhook_url']) : '';
        if (! $clear && $url === '') {
            return back();
        }
        if (! $clear && ! $slack->accepts($url)) {
            throw ValidationException::withMessages([
                'webhook_url' => __('app.workspace.slack_invalid'),
            ]);
        }

        $next = $clear ? null : $url;
        if ($team->slack_webhook_url !== $next) {
            $team->forceFill(['slack_webhook_url' => $next])->save();
            $audit->slackWebhookChanged($this->user($request), $team);
        }

        return back();
    }

    public function updateBranding(Request $request, Team $team, AuditLog $audit): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        if ($request->input('primary') === '') {
            $request->merge(['primary' => null]);
        }
        $data = $request->validate([
            'primary' => ['nullable', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
        ], [
            'primary.regex' => __('app.workspace.color_invalid'),
        ], [
            'primary' => __('app.workspace.primary_color'),
        ]);

        $branding = $team->branding ?? [];
        $primary = is_string($data['primary'] ?? null) && $data['primary'] !== '' ? strtolower($data['primary']) : null;
        $current = is_string($branding['primary'] ?? null) ? strtolower($branding['primary']) : null;
        if ($current !== $primary) {
            if ($primary === null) {
                unset($branding['primary']);
            } else {
                $branding['primary'] = $primary;
            }
            $team->forceFill(['branding' => $branding])->save();
            $audit->reportBrandingChanged($this->user($request), $team);
        }

        return back();
    }

    public function storeApiKey(Request $request, Team $team, TenantApiKeys $keys): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        $data = $request->validate([
            'provider' => ['required_with:key', Rule::enum(ApiKeyProvider::class)],
            'key' => ['nullable', 'string', 'max:4096'],
        ], [], [
            'provider' => __('app.workspace.provider'),
            'key' => __('app.workspace.api_key'),
        ]);
        if (! is_string($data['key'] ?? null) || trim($data['key']) === '') {
            return back();
        }

        $provider = ApiKeyProvider::from((string) $data['provider']);
        $keys->store($this->user($request), $provider, trim($data['key']));

        return back();
    }

    public function updateBudget(Request $request, Team $team, TenantApiKeys $keys): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        $data = $request->validate([
            'budget_usd' => ['nullable', 'string', 'max:13'],
        ], [], [
            'budget_usd' => __('app.workspace.budget'),
        ]);
        if (! array_key_exists('budget_usd', $data)) {
            return back();
        }

        $budget = is_string($data['budget_usd']) && $data['budget_usd'] !== '' ? $data['budget_usd'] : null;
        $keys->setBudget($this->user($request), $budget);

        return back();
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        Gate::authorize('update', $team);
        $tenantId = app(TenantContext::class)->id();
        abort_unless($tenantId !== null && $team->id === $tenantId, 404);
        $this->user($request);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
