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

        return Inertia::render('Admin/Billing', [
            'month' => $month,
            'rows' => array_map($this->displayAmounts(...), $rows),
            'accounts' => array_map($this->displayAmounts(...), $statement->accounts($rows)),
        ]);
    }

    /** Decimal arithmetic and CSV use the exact statement. Only the Inertia display payload is numeric.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function displayAmounts(array $row): array
    {
        foreach (['invoice_total_usd', 'platform_cost_usd', 'byok_cost_usd', 'unassigned_cost_usd', 'markup_usd', 'markup_percent'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (float) $row[$key];
            }
        }
        if (isset($row['engine_costs_usd'])) {
            $row['engine_costs_usd'] = array_map('floatval', $row['engine_costs_usd']);
        }

        return $row;
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
        // ApplyTenantRls wraps this route in a transaction, so the row lock lasts through audit.
        $team = Team::query()->lockForUpdate()->findOrFail($team->id);
        $previous = $team->billing_markup_percent;
        $team->forceFill(['billing_markup_percent' => $data['markup_percent']]);
        if ($previous !== $team->billing_markup_percent) {
            $team->save();
            $audit->billingMarkupChanged($request->user(), $team, $previous);
        }

        return back(303);
    }
}
