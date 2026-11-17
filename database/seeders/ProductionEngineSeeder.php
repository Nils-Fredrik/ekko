<?php

namespace Database\Seeders;

use App\Models\Engine;
use Illuminate\Database\Seeder;

class ProductionEngineSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'chatgpt_api' => ['chatgpt', 'openai', config('engines.chatgpt_api.model')],
            'gemini_api' => ['gemini', 'google', config('engines.gemini_api.model')],
            'claude_haiku' => ['claude', 'anthropic', 'claude-haiku-5-5'],
            'claude_sonnet' => ['claude', 'anthropic', 'claude-sonnet-5-5'],
        ] as $key => [$family, $provider, $model]) {
            // Preserve operator model and activation choices when run again.
            Engine::query()->firstOrCreate(['key' => $key], [
                'family' => $family,
                'method' => 'api',
                'provider' => $provider,
                'model_id' => $model,
                'is_enabled' => $key !== 'gemini_api' || config('engines.gemini_grounding_approved', false),
            ]);
        }

        // Keep historical results; prevent collection by the postponed providers.
        Engine::query()->whereIn('provider', ['dataforseo', 'perplexity'])->update(['is_enabled' => false]);
        // Prices must be approved by the operator; demo rates must never seed production.
    }
}
