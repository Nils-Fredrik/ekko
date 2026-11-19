<?php

namespace App\Http\Controllers\Internal;

use App\Work\CloudTasks\CloudTasksWorkPublisher;
use App\Work\WorkWakeReceiver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The fixed Cloud Tasks targets (T40-F4, ADR 0033). The body carries internal ids only and is validated strictly; a
 * malformed body is refused with 422 and never retried into anything. 204 settles the delivery; 425 Too Early keeps
 * it open, so Cloud Tasks delivers it again after its backoff (no 429/503, which would slow the whole queue, and no
 * 5xx alert). Waking the dispatcher failing gives 503, like the Scheduler tick (ADR 0030). Only ids and the outcome
 * are logged.
 */
final class WorkWakeController
{
    public function signal(Request $request, WorkWakeReceiver $receiver): Response
    {
        $body = $request->json()->all();
        $tenantId = $body['tenant_id'] ?? null;
        $signalId = $body['signal_id'] ?? null;
        if (count($body) !== 2 || ! is_int($tenantId) || ! is_int($signalId) || $tenantId < 1 || $signalId < 1) {
            return response('', 422);
        }

        try {
            $outcome = $receiver->signal($tenantId, $signalId);
        } catch (Throwable $failure) {
            Log::error('work.wake_failed', ['tenant_id' => $tenantId, 'work_signal_id' => $signalId, 'error_type' => $failure::class]);

            return response('', 503);
        }

        Log::info('work.wake', ['tenant_id' => $tenantId, 'work_signal_id' => $signalId, 'outcome' => $outcome]);

        return $this->answer($outcome);
    }

    public function queue(Request $request, WorkWakeReceiver $receiver): Response
    {
        if ($request->json()->all() !== ['queue' => 'default']) {
            return response('', 422);
        }

        try {
            $publisher = config('ekko.work.publisher') === 'cloud_tasks' ? app(CloudTasksWorkPublisher::class) : null;
            $outcome = $receiver->queue($publisher);
        } catch (Throwable $failure) {
            Log::error('work.queue_wake_failed', ['error_type' => $failure::class]);

            return response('', 503);
        }

        Log::info('work.queue_wake', ['outcome' => $outcome]);

        return $this->answer($outcome);
    }

    private function answer(string $outcome): Response
    {
        if (WorkWakeReceiver::open($outcome)) {
            return response('', 425, ['Retry-After' => (string) max(1, (int) config('ekko.work.wake.confirm_after_seconds'))]);
        }

        return response()->noContent();
    }
}
