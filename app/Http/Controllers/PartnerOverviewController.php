<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardPeriod;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Metrics\Metric;
use App\Metrics\MetricsService;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One row per client of the partner tenant the advisor is signed into (docs/spec.md 14.2, T35).
 * Figures are read inside that client's row-level security, never from another partner's clients.
 */
class PartnerOverviewController extends Controller
{
    public function __invoke(Request $request, TenantRls $rls, MetricsService $metrics, TenantAccess $access): Response
    {
        $user = $request->user();
        $partner = $user?->currentTeam;
        abort_unless($user instanceof User && $partner instanceof Team && $this->advises($user, $partner), 403);

        $period = DashboardPeriod::endingToday(28, $partner->timezone);
        $clients = Team::query()
            ->where('partner_team_id', $partner->id)
            ->where('personal_team', false)
            ->where('status', '!=', TenantStatus::Closed->value)
            ->orderBy('name')
            ->get()
            ->filter(fn (Team $client): bool => $access->canAccess($user, $client))
            ->values();

        $rows = [];
        foreach ($clients as $client) {
            $rows[] = $rls->run([$client->id], function () use ($client, $metrics, $period): array {
                $current = [];
                $previous = [];
                $currentN = 0;
                $previousN = 0;
                foreach (Brand::query()->orderBy('name')->get() as $brand) {
                    $now = $metrics->visibility($period->filter($brand->id));
                    $then = $metrics->visibility($period->filter($brand->id, previous: true));
                    if ($now->value !== null) {
                        $current[] = $now->value;
                        $currentN += $now->n;
                    }
                    if ($then->value !== null) {
                        $previous[] = $then->value;
                        $previousN += $then->n;
                    }
                }

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'visibility' => $this->average($current, $currentN),
                    'previous_visibility' => $this->average($previous, $previousN),
                    'alerts' => Alert::query()
                        ->where('triggered_at', '>=', CarbonImmutable::parse($period->from, $period->timezone)->startOfDay())
                        ->count(),
                ];
            });
        }

        return Inertia::render('Partner/Index', [
            'period' => ['days' => $period->days],
            'clients' => $rows,
        ]);
    }

    private function advises(User $user, Team $partner): bool
    {
        if ($partner->personal_team || $partner->partner_team_id !== null || $partner->status === TenantStatus::Closed) {
            return false;
        }

        return $partner->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', TenantRole::PartnerAdvisor->value)
            ->exists();
    }

    /**
     * @param  list<float>  $values
     * @return array{value: float|null, n: int, low_sample: bool}
     */
    private function average(array $values, int $n): array
    {
        return [
            'value' => $values === [] ? null : array_sum($values) / count($values),
            'n' => $n,
            'low_sample' => $n < Metric::LOW_SAMPLE,
        ];
    }
}
