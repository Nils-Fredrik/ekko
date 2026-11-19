<?php

namespace App\Http\Controllers\Internal;

use App\Work\WorkRecovery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The hourly recovery check (T40-F4, ADR 0033), called by Cloud Scheduler with its OIDC identity. Runs on web, so an
 * idle hour costs one request and one indexed read per tenant, no job start. Logs counts only.
 */
final class WorkRecoverController
{
    public function __invoke(WorkRecovery $recovery): Response
    {
        $result = $recovery->sweep(CarbonImmutable::now('UTC'));

        try {
            $queue = $recovery->queue();
        } catch (Throwable $failure) {
            Log::error('work.recovery_failed', ['published' => $result['published'], 'failed' => $result['failed'], 'error_type' => $failure::class]);

            return response('', 503);
        }

        Log::info('work.recovery', [...$result, 'queue' => $queue]);

        return $result['failed'] > 0 ? response('', 503) : response()->noContent();
    }
}
