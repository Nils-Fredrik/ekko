<?php

namespace App\Http\Controllers\Internal;

use App\Scheduling\DispatcherLauncher;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExtractionPollController
{
    public function __invoke(DispatcherLauncher $dispatcher): Response
    {
        try {
            $dispatcher->start();
        } catch (Throwable $failure) {
            Log::error('dispatch.wake_failed', ['error_type' => $failure::class]);

            return response('', 503);
        }

        return response()->noContent();
    }
}
