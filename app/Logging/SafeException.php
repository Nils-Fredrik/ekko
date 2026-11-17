<?php

namespace App\Logging;

use Throwable;

final class SafeException
{
    /**
     * Exception messages, custom context, previous messages and trace arguments may all contain secrets.
     * Keep only code locations and types, even for wrappers around SQL, HTTP or mail failures.
     *
     * @return array{type: string, file: string, line: int, trace: list<array<string, int|string>>, causes: list<string>}
     */
    public static function metadata(Throwable $failure): array
    {
        $trace = [];
        foreach (array_slice($failure->getTrace(), 0, 30) as $frame) {
            $trace[] = array_intersect_key($frame, array_flip(['file', 'line', 'class', 'type', 'function']));
        }

        $causes = [];
        $previous = $failure->getPrevious();
        while ($previous !== null && count($causes) < 10) {
            $causes[] = $previous::class;
            $previous = $previous->getPrevious();
        }

        return ['type' => $failure::class, 'file' => $failure->getFile(), 'line' => $failure->getLine(), 'trace' => $trace, 'causes' => $causes];
    }
}
