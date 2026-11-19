<?php

namespace App\Http\Controllers;

use App\Enums\TenantStatus;
use App\Http\Middleware\SharedReportThrottle;
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
    public function __invoke(Request $request, string $tenant, string $selector, TenantContext $context, TenantRls $rls): Response
    {
        // The fragment secret has not been sent on first navigation. Use the recipient's
        // browser language for generic loading/errors, without reading report content.
        $previous = app()->getLocale();
        $preferred = $request->getPreferredLanguage(config()->array('ekko.locales'));
        if ($request->headers->has('Accept-Language') && is_string($preferred)) {
            app()->setLocale($preferred);
        }
        try {
            return $this->respond($request, $tenant, $selector, $context, $rls);
        } finally {
            app()->setLocale($previous);
        }
    }

    private function respond(Request $request, string $tenant, string $selector, TenantContext $context, TenantRls $rls): Response
    {
        $headers = SharedReportThrottle::HEADERS;
        $token = $request->bearerToken();
        if (! ctype_digit($tenant) || (int) $tenant < 1 || (string) (int) $tenant !== $tenant || preg_match('/\A[a-f0-9]{32}\z/', $selector) !== 1 || ($request->header('Authorization') !== null && (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1))) {
            return $this->notFound($headers);
        }

        // The random selector identifies link state without revealing its separate secret.
        // The secret stays in the fragment and is sent only in the Authorization header.
        return $context->run((int) $tenant, fn (): Response => $rls->run([(int) $tenant], function () use ($request, $selector, $token, $tenant, $headers): Response {
            $query = ReportLink::query()->where('public_id', $selector);
            if ($token !== null) {
                $query->where('token_hash', hash('sha256', $token))->lockForUpdate();
            }
            $link = $query->first();
            if (! $link instanceof ReportLink || $link->revoked_at !== null || $link->expires_at->isPast()) {
                return $this->notFound($headers);
            }
            $team = Team::query()->find((int) $tenant);
            if ($team === null || $team->status === TenantStatus::Closed) {
                return $this->notFound($headers);
            }
            if ($request->header('Authorization') === null) {
                return response()->view('reports.shared-bootstrap', [], 200, $headers + ['Content-Language' => app()->getLocale()]);
            }
            $report = $link->report()->with('reportSchedule.brand')->first();
            if ($report === null) {
                return $this->notFound($headers);
            }
            $schedule = $report->reportSchedule;
            $data = $report->web_snapshot;
            $savedLocale = is_array($data) ? ($data['locale'] ?? null) : null;
            // Snapshots made before the locale was recorded, and reconstructed reports, use the schedule locale.
            $locale = is_string($savedLocale) && $savedLocale !== '' ? $savedLocale : $schedule->locale;
            $previous = app()->getLocale();
            app()->setLocale($locale);
            try {
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

                return response($html, 200, $headers + ['Content-Language' => $locale]);
            } finally {
                app()->setLocale($previous);
            }
        }));
    }

    /** @param array<string, string> $headers */
    private function notFound(array $headers): Response
    {
        return response()->view('reports.shared-missing', [], 404, $headers + ['Content-Language' => app()->getLocale()]);
    }
}
