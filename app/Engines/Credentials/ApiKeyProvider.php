<?php

namespace App\Engines\Credentials;

enum ApiKeyProvider: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Google = 'google';
    case Perplexity = 'perplexity';
}
