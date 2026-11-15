<?php

namespace App\Engines;

interface QueuedEngineAdapter extends EngineAdapter
{
    public function submit(EngineRequest $request): string;

    public function fetch(string $providerTaskId): ?EngineResult;
}
