<?php

namespace Database\Factories;

use App\Models\Response;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Response> */
class ResponseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'engine_key' => fn (array $attributes): string => Run::query()->findOrFail($attributes['run_id'])->engine_key,
            'answer_text' => $this->faker->paragraph(),
            'answer_language' => 'nb',
            'input_tokens' => 250,
            'output_tokens' => 100,
            'search_calls' => 1,
            'cost_usd' => '0.00100000',
            'collected_at' => now(),
        ];
    }
}
