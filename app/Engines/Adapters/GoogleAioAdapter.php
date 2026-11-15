<?php

namespace App\Engines\Adapters;

final class GoogleAioAdapter extends DataForSeoGoogleAdapter
{
    protected const string PATH = '/serp/google/organic';

    protected const array EXTRA_TASK_PARAMETERS = ['load_async_ai_overview' => true];

    public function key(): string
    {
        return 'google_aio';
    }
}
