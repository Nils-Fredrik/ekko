<?php

namespace App\Http\Controllers\Api;

use App\Api\ProductData;
use App\Api\Query;
use App\Models\Team;
use App\Pitch\PitchProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PitchController
{
    public function index(Request $request, PitchProvisioner $pitches): JsonResponse
    {
        $partner = Team::query()->findOrFail(current_tenant_id());
        $pitches->authorize($request->user(), $partner);

        return response()->json(Team::query()->where('partner_team_id', $partner->id)->where('kind', 'pitch')->orderBy('id')
            ->paginate(Query::pageSize($request))->withQueryString()->through(fn (Team $pitch) => ProductData::pitch($pitch)));
    }

    public function store(Request $request, PitchProvisioner $pitches): JsonResponse
    {
        return response()->json(['data' => ProductData::pitch($pitches->create($request->user(), Team::query()->findOrFail(current_tenant_id()), $request->all()))], 201);
    }
}
