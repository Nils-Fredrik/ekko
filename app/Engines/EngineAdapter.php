<?php

namespace App\Engines;

use App\Models\Market;

interface EngineAdapter
{
    public function key(): string;

    public function supports(Market $market): bool;

    public function collect(EngineRequest $request): EngineResult;
}
