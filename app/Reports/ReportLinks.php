<?php

namespace App\Reports;

use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\AuditLog;
use Illuminate\Support\Facades\Gate;

/** One implementation for the web interface, REST and MCP. Plaintext tokens are returned once only. */
final class ReportLinks
{
    /** @return array{id: int, url: string, expires_at: string} */
    public function create(User $actor, Report $report, int $days = 90): array
    {
        Gate::forUser($actor)->authorize('update', $report);
        abort_unless($days >= 1 && $days <= 3650, 422);
        $token = bin2hex(random_bytes(32));
        $link = ReportLink::query()->create([
            'report_id' => $report->id, 'public_id' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays($days), 'created_by' => $actor->id,
        ]);
        app(AuditLog::class)->reportLinkChanged($actor, Team::query()->findOrFail($report->tenant_id), $report->id, $link->id, false);

        return [
            'id' => $link->id,
            'url' => rtrim(config()->string('app.url'), '/').route('reports.shared', ['tenant' => $report->tenant_id, 'selector' => $link->public_id], false).'#'.$token,
            'expires_at' => $link->expires_at->toIso8601String(),
        ];
    }

    public function revoke(User $actor, Report $report, ReportLink $link): void
    {
        Gate::forUser($actor)->authorize('update', $report);
        abort_unless($link->report_id === $report->id, 404);
        $link = ReportLink::query()->lockForUpdate()->findOrFail($link->id);
        if ($link->revoked_at === null) {
            $link->forceFill(['revoked_at' => now()])->save();
            app(AuditLog::class)->reportLinkChanged($actor, Team::query()->findOrFail($report->tenant_id), $report->id, $link->id, true);
        }
    }
}
