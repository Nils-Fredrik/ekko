<?php

namespace App\Http\Controllers\Admin;

use App\Billing\BillingCsv;
use App\Billing\BillingStatement;
use App\Models\Team;
use App\Tenancy\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class BillingController
{
    public function index(Request $request, BillingStatement $statement): InertiaResponse
    {
        Gate::allowIf($request->user()?->is_platform_admin === true);
        $month = $request->string('month', now('UTC')->subMonthNoOverflow()->format('Y-m'))->toString();
        $rows = $statement->rows($request->user(), $month);

        return Inertia::render('Admin/Billing', ['month' => $month, 'rows' => $rows, 'accounts' => $statement->accounts($rows)]);
    }

    public function export(Request $request, BillingStatement $statement, BillingCsv $csv): Response
    {
        Gate::allowIf($request->user()?->is_platform_admin === true);
        $month = $request->string('month')->toString();

        return response($csv->render($statement->rows($request->user(), $month)), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="ekko-billing-'.$month.'.csv"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, Team $team, AuditLog $audit): RedirectResponse
    {
        Gate::allowIf($request->user()?->is_platform_admin === true);
        abort_if($team->personal_team, 404);
        $data = $request->validate(['markup_percent' => ['present', 'nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999.9999']]);
        $team = Team::query()->lockForUpdate()->findOrFail($team->id);
        $previous = $team->billing_markup_percent;
        $team->forceFill(['billing_markup_percent' => $data['markup_percent']])->save();
        $audit->billingMarkupChanged($request->user(), $team, $previous);

        return back(303);
    }
}
