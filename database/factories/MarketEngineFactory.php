<?php

namespace Database\Factories;

use App\Models\Engine;
use App\Models\Market;
use App\Models\MarketEngine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketEngine> */
class MarketEngineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'market_id' => Market::factory(),
            'engine_key' => Engine::factory(),
            'frequency' => 'daily',
            'prompt_scope' => 'all',
            'repetitions' => 1,
        ];
    }
}
