<?php

namespace App\Http\Controllers\Api;

use App\Api\ProductData;
use App\Api\Query;
use App\Models\Report;
use App\Models\ReportLink;
use App\Reports\ReportLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class ReportController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Report::class);

        return response()->json(Report::query()->orderByDesc('id')->paginate(Query::pageSize($request))
            ->withQueryString()->through(fn (Report $report) => ProductData::report($report)));
    }

    public function show(Report $report): JsonResponse
    {
        Gate::authorize('view', $report);

        return response()->json(['data' => ProductData::report($report)]);
    }

    public function storeLink(Request $request, Report $report, ReportLinks $links): JsonResponse
    {
        Gate::authorize('update', $report);
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:3650']]);

        return response()->json(['data' => $links->create($request->user(), $report, (int) ($data['days'] ?? 90))], 201,
            ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function destroyLink(Request $request, Report $report, ReportLinks $links): Response
    {
        Gate::authorize('update', $report);
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        $links->revoke($request->user(), $report, ReportLink::query()->where('report_id', $report->id)->findOrFail($data['id']));

        return response()->noContent();
    }
}
