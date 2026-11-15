<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $basis = ['chatgpt_ui', 'gemini_ui', 'google_aio', 'google_ai_mode'];
        $standard = [...$basis, 'claude_haiku', 'claude_sonnet', 'perplexity_api'];

        Plan::query()->firstOrCreate(['name' => 'Basis'], [
            'max_brands' => 1,
            'max_markets_per_brand' => 1,
            'max_prompts_per_market' => 30,
            'allowed_engines' => $basis,
            'monthly_call_quota' => 4000,
        ]);

        Plan::query()->firstOrCreate(['name' => 'Standard'], [
            'max_brands' => 3,
            'max_markets_per_brand' => 2,
            'max_prompts_per_market' => 50,
            'allowed_engines' => $standard,
            'engine_max_frequencies' => ['claude_sonnet' => 'weekly', 'perplexity_api' => 'weekly'],
            'monthly_call_quota' => 50000,
        ]);

        Plan::query()->firstOrCreate(['name' => 'Byrå'], [
            'max_prompts_per_market' => 100,
            'allowed_engines' => [...$standard, 'chatgpt_api', 'gemini_api'],
        ]);
    }
}
