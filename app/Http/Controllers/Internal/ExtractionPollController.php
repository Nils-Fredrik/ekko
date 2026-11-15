<?php

namespace App\Http\Controllers\Internal;

use App\Scheduling\Dispatcher;
use App\Scheduling\WorkerLauncher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

final class ExtractionPollController
{
    public function __invoke(Dispatcher $dispatcher, WorkerLauncher $worker): Response
    {
        $dispatcher->dispatch(CarbonImmutable::now('UTC'), plan: false);
        $worker->startIfPending();

        return response()->noContent();
    }
}
