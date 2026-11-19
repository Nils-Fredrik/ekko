<?php

namespace Tests\Support\Work;

use App\Engines\EngineAdapter;
use App\Engines\EngineRequest;
use App\Engines\EngineResult;
use App\Models\Market;

/** A synchronous engine whose answers the test sets; it never calls a provider. */
final class ChainEngineAdapter implements EngineAdapter
{
    /** @var list<string> */
    public array $prompts = [];

    public function __construct(private readonly string $engineKey, public string $answer = 'Jeg anbefaler Fjordkaffe og Nordlys Rosteri.') {}

    public function key(): string
    {
        return $this->engineKey;
    }

    public function supports(Market $market): bool
    {
        return true;
    }

    public function collect(EngineRequest $request): EngineResult
    {
        $this->prompts[] = $request->promptText;

        return new EngineResult($this->answer, 'fixture-model-1', [], 100, 40, 0, ['fixture' => true]);
    }
}
