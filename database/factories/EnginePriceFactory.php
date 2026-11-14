<?php

namespace Database\Factories;

use App\Models\Engine;
use App\Models\EnginePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EnginePrice> */
class EnginePriceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'engine_key' => Engine::factory(),
            'valid_from' => now()->startOfDay(),
            'price_per_call_usd' => '0.00100000',
            'price_per_1k_input_tokens_usd' => '0.00000000',
            'price_per_1k_output_tokens_usd' => '0.00000000',
            'price_per_search_usd' => '0.00000000',
        ];
    }
}
