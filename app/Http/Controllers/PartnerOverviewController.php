<?php

namespace App\Http\Controllers;

use App\Dashboard\PartnerGraphs;
use App\Enums\TenantStatus;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The portfolio of the partner tenant the advisor is signed into (docs/spec.md 14.2, T35, docs/ui-forbedring.md
 * 7.7, U10). The first response only names the clients, so the page is quick with many clients. Visibility,
 * minigraph, change, open alerts and open actions follow as one deferred prop, read inside each client's
 * row-level security and never from another partner's clients (PartnerGraphs).
 */
class PartnerOverviewController extends Controller
{
    public function __invoke(Request $request, TenantAccess $access, PartnerGraphs $graphs): Response
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

        return Inertia::render('Partner/Index', [
            'period' => ['days' => PartnerGraphs::DAYS],
            'clients' => $clients->map(fn (Team $client): array => ['id' => $client->id, 'name' => $client->name])->all(),
            'portfolioGraphs' => Inertia::defer(fn (): array => $graphs->forClients($user, $clients)),
        ]);
    }
}
