<?php

namespace App\Jobs;

use App\BotLogs\BotHits;
use App\BotLogs\BrandLogDomains;
use App\BotLogs\CombinedAccessLog;
use App\BotLogs\InvalidAccessLog;
use App\Models\BotLogUpload;
use App\Models\Brand;
use App\Models\Team;
use Illuminate\Contracts\Encryption\DecryptException;

final class ImportBotLog extends TenantJob
{
    public function __construct(int $tenantId, public readonly int $uploadId)
    {
        parent::__construct($tenantId);
    }

    public function handle(CombinedAccessLog $parser, BotHits $hits): void
    {
        $upload = BotLogUpload::query()->where('status', 'pending')->find($this->uploadId);
        if ($upload === null) {
            return;
        }

        $brand = Brand::query()->lockForUpdate()->find($upload->brand_id);
        $upload = BotLogUpload::query()->where('status', 'pending')->lockForUpdate()->find($this->uploadId);
        if ($upload === null || $brand === null) {
            return;
        }

        try {
            if ($brand->status === 'archived' || ! Team::query()->find($this->tenantId)?->isActive()
                || ! array_key_exists($upload->domain, BrandLogDomains::for($brand))
                || $brand->integrations()->where('type', 'clickhouse_logs')->exists()) {
                throw new InvalidAccessLog('This upload no longer has an eligible brand and domain.');
            }

            $compressed = base64_decode($upload->payload ?? '', true);
            if ($compressed === false) {
                throw new InvalidAccessLog('Invalid encoded file.');
            }

            $data = $parser->aggregate($compressed, $upload->domain, config()->array('ekko.bot_logs.bots'));
        } catch (InvalidAccessLog|DecryptException) {
            $upload->update(['status' => 'rejected', 'payload' => null]);

            return;
        }

        $hits->replace($brand, $data['dates'], $data['hits'], $upload->domain);
        $upload->update(['status' => 'processed', 'payload' => null]);
    }
}
