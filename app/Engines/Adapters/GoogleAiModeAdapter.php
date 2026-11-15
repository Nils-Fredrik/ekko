<?php

namespace App\Engines\Adapters;

final class GoogleAiModeAdapter extends DataForSeoGoogleAdapter
{
    protected const string PATH = '/serp/google/ai_mode';

    public function key(): string
    {
        return 'google_ai_mode';
    }
}
