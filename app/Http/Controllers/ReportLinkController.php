<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportLink;
use App\Reports\ReportLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ReportLinkController extends Controller
{
    public function store(Request $request, Report $report, ReportLinks $links): JsonResponse
    {
        Gate::authorize('update', $report);
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:3650']]);

        return response()->json($links->create($request->user(), $report, (int) ($data['days'] ?? 90)), 201,
            ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function destroy(Request $request, Report $report, ReportLink $link, ReportLinks $links): JsonResponse
    {
        $links->revoke($request->user(), $report, $link);

        return response()->json([], 200, ['Cache-Control' => 'private, no-store']);
    }
}
