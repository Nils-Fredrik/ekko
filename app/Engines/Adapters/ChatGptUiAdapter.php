<?php

namespace App\Engines\Adapters;

final class ChatGptUiAdapter extends DataForSeoScraperAdapter
{
    protected const string PATH = '/ai_optimization/chat_gpt/llm_scraper';

    public function key(): string
    {
        return 'chatgpt_ui';
    }
}
