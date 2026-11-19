<?php

namespace App\Work;

use RuntimeException;

/**
 * The lease expired and another processor took the signal over (ADR 0032). The processing transaction rolls back,
 * so nothing is acknowledged for work the other processor now owns.
 */
final class LeaseLost extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('work_signal_lease_lost');
    }
}
