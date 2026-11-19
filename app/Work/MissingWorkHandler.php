<?php

namespace App\Work;

use RuntimeException;

/**
 * No handler is configured for a kind of work (ekko.work.handlers). Treated as a failure, so the signal stays
 * pending with backoff and the error log shows it, instead of being acknowledged unprocessed.
 */
final class MissingWorkHandler extends RuntimeException
{
    public function __construct(WorkKind $kind)
    {
        parent::__construct('missing_work_handler:'.$kind->value);
    }
}
