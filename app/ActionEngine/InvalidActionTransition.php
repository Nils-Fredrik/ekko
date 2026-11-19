<?php

namespace App\ActionEngine;

use App\Enums\ActionStatus;
use DomainException;

final class InvalidActionTransition extends DomainException
{
    public static function between(ActionStatus $from, ActionStatus $to): self
    {
        return new self("An action cannot move from {$from->value} to {$to->value}.");
    }
}
