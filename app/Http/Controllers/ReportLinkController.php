<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ReportLinkController extends Controller
{
    public function store(Request $request, Report $report): JsonResponse
    {
        Gate::authorize('update', $report);
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:3650']]);
        $token = bin2hex(random_bytes(32));
        $link = ReportLink::query()->create([
            'report_id' => $report->id,
            'public_id' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) ($data['days'] ?? 90)),
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'id' => $link->id,
            'url' => rtrim(config()->string('app.url'), '/').route('reports.shared', ['tenant' => $report->tenant_id, 'selector' => $link->public_id], false).'#'.$token,
            'expires_at' => $link->expires_at->toIso8601String(),
        ], 201, ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function destroy(Report $report, ReportLink $link): JsonResponse
    {
        Gate::authorize('update', $report);
        abort_unless($link->report_id === $report->id, 404);
        if ($link->revoked_at === null) {
            $link->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json([], 200, ['Cache-Control' => 'private, no-store']);
    }
}
