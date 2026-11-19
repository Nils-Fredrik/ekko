<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\AuditLog;
use App\Tenancy\InvalidPartnerException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TenantController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Plan::class);

        $tenants = Team::query()->where('personal_team', false)->with(['plan:id,name', 'partner:id,name'])->orderBy('name')->get();

        return Inertia::render('Admin/Tenants', [
            'tenants' => $tenants->map(fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'kind' => $team->kind,
                'pitch_expires_at' => $team->pitch_expires_at?->toIso8601String(),
                'status' => $team->status->value,
                'plan_id' => $team->plan_id,
                'plan' => $team->plan->name ?? null,
                'partner_team_id' => $team->partner_team_id,
                'partner' => $team->partner->name ?? null,
                'monthly_call_quota_override' => $team->monthly_call_quota_override,
            ]),
            'plans' => Plan::query()->where('is_pitch_plan', false)->orderBy('name')->get(['id', 'name']),
            'partners' => Team::query()->where('personal_team', false)->whereNull('partner_team_id')->where('status', '<>', TenantStatus::Closed->value)->orderBy('name')->get(['id', 'name']),
            'statuses' => array_column(TenantStatus::cases(), 'value'),
        ]);
    }

    public function store(Request $request, AuditLog $audit): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        Gate::authorize('create', Team::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_pitch_plan', false)],
            'partner_team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ]);
        $this->guardPartner($data['partner_team_id'] ?? null, null);

        try {
            DB::transaction(function () use ($admin, $data, $audit): void {
                $team = $admin->ownedTeams()->create([
                    'name' => $data['name'],
                    'personal_team' => false,
                ]);
                $team->forceFill([
                    'plan_id' => $data['plan_id'],
                    'partner_team_id' => $data['partner_team_id'] ?? null,
                ])->save();
                $audit->tenantCreated($admin, $team);
            });
        } catch (InvalidPartnerException) {
            throw ValidationException::withMessages(['partner_team_id' => __('admin.tenants.invalid_partner')]);
        }

        return back(303);
    }

    public function update(Request $request, Team $team, AuditLog $audit): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        Gate::authorize('changePlan', $team);
        abort_if($team->personal_team, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_pitch_plan', false)],
            'partner_team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'status' => ['required', Rule::enum(TenantStatus::class)],
            'monthly_call_quota_override' => ['nullable', 'integer', 'min:0'],
        ]);
        $this->guardPartner($data['partner_team_id'] ?? null, $team);

        $previousName = $team->name;
        $previousStatus = $team->status;
        $previous = [
            'plan_id' => $team->plan_id,
            'partner_team_id' => $team->partner_team_id,
            'status' => $team->status->value,
            'monthly_call_quota_override' => $team->monthly_call_quota_override,
        ];
        $status = TenantStatus::from($data['status']);
        // Spec 16 describes closing, not reopening. A closed tenant stays closed until purge.
        if ($previousStatus === TenantStatus::Closed && $status !== TenantStatus::Closed) {
            throw ValidationException::withMessages(['status' => __('admin.tenants.reopen_closed')]);
        }
        $closedAt = $team->closed_at;
        if ($status === TenantStatus::Closed && $previousStatus !== TenantStatus::Closed) {
            // Same gate as DeleteTeam: a hijacked admin session alone cannot start the purge clock.
            $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
            if (Date::now()->getTimestamp() - $confirmedAt > (int) config('auth.password_timeout')) {
                throw ValidationException::withMessages(['password' => __('app.tenant_close.confirm_password')]);
            }
            $closedAt = Date::now();
        }

        try {
            $team->forceFill([
                'name' => $data['name'],
                'plan_id' => $data['plan_id'],
                'partner_team_id' => $data['partner_team_id'] ?? null,
                'status' => $status,
                'monthly_call_quota_override' => $data['monthly_call_quota_override'] ?? null,
                'closed_at' => $closedAt,
            ])->save();
        } catch (InvalidPartnerException) {
            throw ValidationException::withMessages(['partner_team_id' => __('admin.tenants.invalid_partner')]);
        }

        if ($previousName !== $team->name) {
            $audit->tenantRenamed($admin, $team, $previousName);
        }
        if ($status === TenantStatus::Closed && $previousStatus !== TenantStatus::Closed) {
            $audit->tenantClosed($admin, $team);
        }
        $current = [
            'plan_id' => $team->plan_id,
            'partner_team_id' => $team->partner_team_id,
            'status' => $team->status->value,
            'monthly_call_quota_override' => $team->monthly_call_quota_override,
        ];
        if ($previous !== $current) {
            $audit->tenantSettingsChanged($admin, $team, $previous);
        }

        return back(303);
    }

    private function guardPartner(?int $partnerId, ?Team $tenant): void
    {
        if ($partnerId === null) {
            return;
        }

        $partner = Team::query()->find($partnerId);
        if ($partner === null || $partner->personal_team || $partner->partner_team_id !== null || $partner->status === TenantStatus::Closed || $partner->id === $tenant?->id) {
            throw ValidationException::withMessages(['partner_team_id' => __('admin.tenants.invalid_partner')]);
        }
    }
}
