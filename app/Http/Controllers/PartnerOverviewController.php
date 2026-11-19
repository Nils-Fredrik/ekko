<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardPeriod;
use App\Enums\TenantStatus;
use App\Metrics\Metric;
use App\Metrics\MetricsService;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
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
    public function __invoke(Request $request, TenantRls $rls, MetricsService $metrics, TenantAccess $access, TenantContext $context): Response
    {
        $user = $request->user();
        $partner = $user?->currentTeam;
        abort_unless($user instanceof User && $partner instanceof Team && $access->advisesPartner($user, $partner), 403);

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
            $rows[] = $context->run($client->id, fn (): array => $rls->run([$client->id], function () use ($client, $metrics): array {
                $period = DashboardPeriod::endingToday(28, $client->timezone);
                $current = [];
                $previous = [];
                foreach (Brand::query()->orderBy('name')->get() as $brand) {
                    $now = $metrics->visibility($period->filter($brand->id));
                    $then = $metrics->visibility($period->filter($brand->id, previous: true));
                    if ($now->value !== null) {
                        $current[] = ['value' => $now->value, 'n' => $now->n];
                    }
                    if ($then->value !== null) {
                        $previous[] = ['value' => $then->value, 'n' => $then->n];
                    }
                }

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'kind' => $client->kind,
                    'pitch_expires_at' => $client->pitch_expires_at?->toIso8601String(),
                    'visibility' => $this->average($current),
                    'previous_visibility' => $this->average($previous),
                    'alerts' => Alert::query()
                        ->where('triggered_at', '>=', CarbonImmutable::parse($period->from, $period->timezone)->startOfDay())
                        ->count(),
                ];
            }));
        }

        return Inertia::render('Partner/Index', [
            'period' => ['days' => 28],
            'clients' => $rows,
        ]);
    }

    /**
     * Visibility is weighted by each brand's sample size, so a small brand does not count as much as a large one.
     *
     * @param  list<array{value: float, n: int}>  $samples
     * @return array{value: float|null, n: int, low_sample: bool}
     */
    private function average(array $samples): array
    {
        $n = 0;
        $weighted = 0.0;
        foreach ($samples as $sample) {
            $n += $sample['n'];
            $weighted += $sample['value'] * $sample['n'];
        }

        return [
            'value' => $n === 0 ? null : $weighted / $n,
            'n' => $n,
            'low_sample' => $n < Metric::LOW_SAMPLE,
        ];
    }
}
