<?php

namespace App\Http\Controllers;

use App\Enums\TenantStatus;
use App\Models\ReportLink;
use App\Models\ReportLinkView;
use App\Models\Team;
use App\Reports\ReportActions;
use App\Reports\ReportPublisher;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SharedReportController extends Controller
{
    public function __invoke(Request $request, string $tenant, TenantContext $context, TenantRls $rls): Response
    {
        $headers = [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'X-Content-Type-Options' => 'nosniff',
        ];
        // The secret stays in the URL fragment, which browsers do not send to proxies or access logs.
        // The bootstrap sends it only in an Authorization header and makes no third-party requests.
        if ($request->header('Authorization') === null) {
            return response()->view('reports.shared-bootstrap', [], 200, $headers + [
                'Content-Security-Policy' => "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'",
            ]);
        }
        $token = $request->bearerToken();
        if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1 || ! ctype_digit($tenant) || (int) $tenant < 1 || (string) (int) $tenant !== $tenant) {
            return response('Not Found', 404, $headers);
        }

        return $context->run((int) $tenant, fn (): Response => $rls->run([(int) $tenant], function () use ($token, $tenant, $headers): Response {
            $link = ReportLink::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $link instanceof ReportLink || $link->revoked_at !== null || $link->expires_at->isPast()) {
                return response('Not Found', 404, $headers);
            }
            $report = $link->report()->with('reportSchedule.brand')->first();
            $team = Team::query()->find((int) $tenant);
            if ($report === null || $team === null || $team->status === TenantStatus::Closed) {
                return response('Not Found', 404, $headers);
            }
            $schedule = $report->reportSchedule;
            $previous = app()->getLocale();
            app()->setLocale($schedule->locale);
            try {
                $data = $report->web_snapshot;
                if (! is_array($data)) {
                    // Earlier PDFs have no stored insight or immutable data. Preserve the published action
                    // section and mark the reconstructed view as such instead of inventing an insight.
                    $data = app(ReportPublisher::class)->viewData($team, $schedule, $report->period_start->toDateString(), $report->period_end->toDateString(), null);
                    $data['actions'] = app(ReportActions::class)->published($report->action_section);
                    $data['reconstructed'] = true;
                }
                $data['shared'] = true;
                $html = view('reports.pdf', $data)->render();
                $now = now();
                $link->forceFill(['views' => $link->views + 1, 'last_viewed_at' => $now])->save();
                ReportLinkView::query()->create(['report_link_id' => $link->id, 'viewed_at' => $now]);

                return response($html, 200, $headers + [
                    'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; frame-ancestors 'none'",
                ]);
            } finally {
                app()->setLocale($previous);
            }
        }));
    }
}
