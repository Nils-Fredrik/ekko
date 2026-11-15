<?php

namespace App\AnthropicBudget;

use RuntimeException;

final class CreditExhausted extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('anthropic_credits_exhausted');
    }
}
