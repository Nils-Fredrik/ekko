<?php

namespace App\Usage;

enum BillingAccount: string
{
    case Platform = 'platform';
    case Byok = 'byok';
}
