<?php

namespace App\Jobs;

use App\Alerts\AlertRules;
use App\Alerts\AlertType;
use App\Integrations\Ga4\Ga4Client;
use App\Integrations\Ga4\Ga4SyncFailed;
use App\Models\AiReferralDaily;
use App\Models\Integration;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/** @phpstan-import-type Referral from Ga4Client */
final class SyncGa4Integration extends TenantJob
{
    public function __construct(int $tenantId, public readonly int $integrationId)
    {
        parent::__construct($tenantId);
    }

    public function handle(Ga4Client $ga4): void
    {
        $team = Team::query()->find($this->tenantId);
        if (! $team?->isActive()) {
            return;
        }

        $integration = $this->lockIntegration();
        if ($integration === null) {
            return;
        }

        $today = CarbonImmutable::today($team->timezone);
        $startDate = $today->subDays(3)->toDateString();
        $endDate = $today->subDay()->toDateString();

        try {
            $report = $ga4->report($integration->config, config()->array('ga4.sources'), $startDate, $endDate);
        } catch (DecryptException|JsonException) {
            $this->recordFailure($integration, $today, 'invalid_config');

            return;
        } catch (Ga4SyncFailed $failure) {
            $this->recordFailure($integration, $today, $failure->getMessage());

            return;
        }

        $this->storeReport($integration->brand_id, $startDate, $endDate, $report);
        $integration->recordSync();
    }

    public function failed(?Throwable $exception): void
    {
        // Laravel calls the failure hook after the job's transaction and tenant context unwind.
        app(TenantContext::class)->run($this->tenantId, fn () => app(TenantRls::class)->run([$this->tenantId], function (): void {
            $team = Team::query()->find($this->tenantId);
            if (! $team?->isActive()) {
                return;
            }

            $integration = $this->lockIntegration();
            if ($integration !== null) {
                $this->recordFailure($integration, CarbonImmutable::today($team->timezone), 'unexpected_failure');
            }
        }));
    }

    private function lockIntegration(): ?Integration
    {
        // Keep report writes and failure tracking serialized for the same integration.
        return Integration::query()->where('type', 'ga4')->whereRelation('brand', 'status', 'active')
            ->lockForUpdate()->find($this->integrationId);
    }

    /** @param array{rows: array<string, Referral>, complete: bool} $report */
    private function storeReport(int $brandId, string $startDate, string $endDate, array $report): void
    {
        if ($report['complete']) {
            $staleIds = AiReferralDaily::query()->where('brand_id', $brandId)
                ->whereBetween('date', [$startDate, $endDate])->get(['id', 'source', 'date'])
                ->reject(fn (AiReferralDaily $row) => isset($report['rows'][$row->date->toDateString().'|'.$row->source]))
                ->modelKeys();
            AiReferralDaily::query()->whereKey($staleIds)->delete();
        }

        $rows = array_map(fn (array $row) => ['brand_id' => $brandId, ...$row], array_values($report['rows']));
        AiReferralDaily::query()->upsert(
            $rows,
            ['tenant_id', 'brand_id', 'source', 'date'],
            ['sessions', 'engaged_sessions', 'conversions'],
        );
    }

    private function recordFailure(Integration $integration, CarbonImmutable $today, string $reason): void
    {
        $previousFailure = $integration->last_failed_on?->toDateString();
        $integration->recordSync($today);

        Log::warning('GA4 integration sync failed.', [
            'tenant_id' => $this->tenantId,
            'integration_id' => $integration->id,
            'error_code' => $reason,
        ]);

        if ($previousFailure !== $today->subDay()->toDateString()) {
            return;
        }

        $rule = app(AlertRules::class)->forSource(AlertType::IntegrationError, $integration->brand_id);

        if ($rule->is_active) {
            $rule->alerts()->create([
                'triggered_at' => now(),
                'payload' => [
                    'integration_id' => $integration->id,
                    'integration_type' => 'ga4',
                    'error_code' => $reason,
                ],
            ]);
        }
    }
}
