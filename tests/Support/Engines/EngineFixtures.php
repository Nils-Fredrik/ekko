<?php

namespace Tests\Support\Engines;

use UnexpectedValueException;

final class EngineFixtures
{
    /** @return array<array-key, mixed> */
    public static function load(string $engine, string $case): array
    {
        $path = dirname(__DIR__, 2)."/Fixtures/Engines/{$engine}/{$case}.json";
        $contents = file_get_contents($path);
        $fixture = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($fixture)) {
            throw new UnexpectedValueException("Invalid engine fixture at {$path}.");
        }

        return $fixture;
    }
}
