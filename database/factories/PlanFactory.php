<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'max_brands' => 1,
            'max_markets_per_brand' => 1,
            'max_prompts_per_market' => 30,
            'allowed_engines' => ['chatgpt_ui', 'gemini_ui', 'google_aio', 'google_ai_mode'],
            'max_frequency' => 'daily',
            'max_repetitions' => 1,
            'monthly_call_quota' => 4000,
            'monthly_budget_usd' => null,
        ];
    }
}
