<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/** Also protect direct PSR logger calls that bypass Laravel's report callback. */
final class SanitizeExceptions implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $found = false;
        $context = $this->sanitize($record->context, $found);
        $extra = $this->sanitize($record->extra, $found);

        return $record->with(message: $found ? 'Application exception.' : $record->message, context: $context, extra: $extra);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function sanitize(array $values, bool &$found, int $depth = 0): array
    {
        if ($depth > 10) {
            return [];
        }
        foreach ($values as $key => $value) {
            if ($value instanceof Throwable) {
                $found = true;
                $values[$key] = SafeException::metadata($value);
            } elseif (is_array($value)) {
                $values[$key] = $this->sanitize($value, $found, $depth + 1);
            }
        }

        return $values;
    }
}
