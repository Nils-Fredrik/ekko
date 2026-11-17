<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

final class Handler extends ExceptionHandler
{
    public function renderForConsole($output, \Throwable $e)
    {
        if (app()->environment('production')) {
            // Artisan otherwise prints the original SQL/mail exception to the container's output
            // after report() has already emitted the safe structured record.
            $output->writeln('Command failed. See the structured application log for code locations.');

            return;
        }

        parent::renderForConsole($output, $e);
    }
}
