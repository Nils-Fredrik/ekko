<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Pitch\PitchProjects;
use App\Tenancy\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class PitchController extends Controller
{
    public function store(Request $request, PitchProjects $projects, TenantAccess $access): RedirectResponse
    {
        $actor = $request->user();
        $partner = $actor?->currentTeam;
        abort_unless($actor instanceof User && $partner instanceof Team && $access->advisesPartner($actor, $partner), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $projects->create($actor, $partner, $data['name'], CarbonImmutable::now('UTC'));

        return back(303);
    }

    public function adminStore(Request $request, PitchProjects $projects): RedirectResponse
    {
        $actor = $request->user();
        Gate::allowIf($actor?->is_platform_admin === true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'partner_team_id' => ['required', 'integer', 'exists:teams,id'],
        ]);
        $projects->create($actor, Team::query()->findOrFail($data['partner_team_id']), $data['name'], CarbonImmutable::now('UTC'));

        return back(303);
    }

    public function convert(Request $request, Team $team, PitchProjects $projects): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $projects->convert($actor, $team, CarbonImmutable::now('UTC'));

        return back(303);
    }
}
