<?php

namespace App\Engines\Adapters;

final class GeminiUiAdapter extends DataForSeoScraperAdapter
{
    protected const string PATH = '/ai_optimization/gemini/llm_scraper';

    protected const ?string LOCATIONS_PATH = self::PATH.'/locations';

    public function key(): string
    {
        return 'gemini_ui';
    }
}
