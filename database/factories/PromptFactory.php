<?php

namespace Database\Factories;

use App\Models\Market;
use App\Models\Prompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Prompt> */
class PromptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $text = rtrim($this->faker->unique()->sentence(), '.').'?';

        return [
            'market_id' => Market::factory(),
            'topic_id' => null,
            'text' => $text,
            'intent' => 'category',
            'funnel_stage' => 'awareness',
            'priority' => 'standard',
            'is_active' => true,
            'text_hash' => fn (array $attributes): string => hash('sha256', $attributes['text']),
        ];
    }
}
