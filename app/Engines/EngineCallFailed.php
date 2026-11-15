<?php

namespace App\Engines;

use RuntimeException;

final class EngineCallFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
