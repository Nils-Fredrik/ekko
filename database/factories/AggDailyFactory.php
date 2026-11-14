<?php

namespace Database\Factories;

use App\Models\AggDaily;
use App\Models\Engine;
use App\Models\Market;
use App\Models\Prompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AggDaily> */
class AggDailyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'market_id' => Market::factory(),
            'brand_id' => fn (array $attributes): int => Market::query()->findOrFail($attributes['market_id'])->brand_id,
            'engine_key' => Engine::factory(),
            'prompt_id' => fn (array $attributes): int => Prompt::factory()->create([
                'market_id' => $attributes['market_id'],
            ])->id,
            'date' => today(),
            'responses' => 1,
            'brand_mentioned' => 1,
            'brand_recommended' => 1,
            'sum_position' => 1,
            'count_position' => 1,
            'sum_sentiment' => '0.750',
            'own_cited' => 1,
            'competitor_mentions' => [],
        ];
    }
}
