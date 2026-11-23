<?php

namespace App\Console\Commands;

use App\Alerts\AlertRules;
use App\Alerts\AlertType;
use App\BotLogs\BotHits;
use App\BotLogs\BrandLogDomains;
use App\BotLogs\ClickHouseBotHits;
use App\BotLogs\ClickHouseSyncFailed;
use App\Models\BotLogUpload;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;

final class SyncBotHits extends Command
{
    protected $signature = 'ekko:sync-bot-hits {--date= : Completed UTC date to sync (YYYY-MM-DD)}';

    protected $description = 'Sync daily AI bot hits from ClickHouse';

    public function handle(TenantContext $context, TenantRls $rls, ClickHouseBotHits $clickHouse, BotHits $hits): int
    {
        try {
            $date = $this->date();
        } catch (InvalidArgumentException) {
            $this->error(__('bot_logs.invalid_date'));

            return self::INVALID;
        }

        $failed = 0;
        foreach (Team::query()->where('personal_team', false)->lazyById() as $team) {
            $context->run($team->id, function () use ($rls, $team, $date, $clickHouse, $hits, &$failed): void {
                $integrationIds = $rls->run([$team->id], function () use ($team) {
                    BotLogUpload::query()->where('status', 'pending')->where('created_at', '<', now()->subDays(7))
                        ->update(['status' => 'rejected', 'payload' => null]);

                    return $team->isActive()
                        ? Integration::query()->where('type', 'clickhouse_logs')->pluck('id')
                        : [];
                });

                foreach ($integrationIds as $integrationId) {
                    $ok = $rls->run([$team->id], fn (): bool => $this->sync($integrationId, $date, CarbonImmutable::today($team->timezone), $clickHouse, $hits));
                    if (! $ok) {
                        $failed++;
                        Log::warning('ClickHouse bot hit sync failed.', ['tenant_id' => $team->id, 'integration_id' => $integrationId]);
                    }
                }
            });
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function sync(int $id, CarbonImmutable $date, CarbonImmutable $today, ClickHouseBotHits $clickHouse, BotHits $hits): bool
    {
        $integration = Integration::query()->where('type', 'clickhouse_logs')->find($id);
        if ($integration === null) {
            return true;
        }

        $brand = Brand::query()->unarchived()->find($integration->brand_id);
        if ($brand === null) {
            return true;
        }

        $domains = BrandLogDomains::for($brand);
        try {
            if ($domains === []) {
                throw new ClickHouseSyncFailed('The brand has no registered domain.');
            }
            /** @var array<string, mixed> $settings */
            $settings = $integration->getAttribute('config');
            $rows = $clickHouse->forDay($settings, $date, $domains, config()->array('ekko.bot_logs.bots'));
        } catch (ClickHouseSyncFailed|DecryptException|JsonException) {
            $rows = null;
        }

        // Take the write locks after the network call. Concurrent edits may invalidate this snapshot.
        $lockedBrand = Brand::query()->unarchived()->lockForUpdate()->find($brand->id);
        $lockedIntegration = Integration::query()->where('type', 'clickhouse_logs')->lockForUpdate()->find($id);
        if ($lockedBrand === null || $lockedIntegration === null
            || $lockedIntegration->brand_id !== $brand->id
            || $lockedIntegration->getRawOriginal('config') !== $integration->getRawOriginal('config')
            || BrandLogDomains::for($lockedBrand) != $domains) {
            return true;
        }

        if ($rows === null) {
            $this->recordFailure($lockedIntegration, $today);

            return false;
        }

        $hits->replace($lockedBrand, [$date->toDateString()], $rows);
        $lockedIntegration->recordSync();

        return true;
    }

    private function recordFailure(Integration $integration, CarbonImmutable $today): void
    {
        $previousFailure = $integration->last_failed_on?->toDateString();
        // The integration row is locked. Retries on the same local day cannot duplicate the alert.
        $integration->recordSync($today);
        if ($previousFailure !== $today->subDay()->toDateString()) {
            return;
        }

        $rule = app(AlertRules::class)->forSource(AlertType::IntegrationError, $integration->brand_id);

        if ($rule->is_active) {
            $rule->alerts()->create([
                'triggered_at' => now(),
                'payload' => [
                    'integration_id' => $integration->id,
                    'integration_type' => 'clickhouse_logs',
                    'error_code' => 'sync_failed',
                ],
            ]);
        }
    }

    private function date(): CarbonImmutable
    {
        $today = CarbonImmutable::today('UTC');
        $value = $this->option('date');
        if ($value === null) {
            return $today->subDay();
        }
        if (! CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d')) {
            throw new InvalidArgumentException('Invalid sync date.');
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $value || $date >= $today) {
            throw new InvalidArgumentException('Invalid sync date.');
        }

        return $date;
    }
}
