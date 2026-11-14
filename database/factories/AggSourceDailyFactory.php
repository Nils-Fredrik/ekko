<?php

namespace Database\Factories;

use App\Models\AggSourceDaily;
use App\Models\Engine;
use App\Models\Market;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AggSourceDaily> */
class AggSourceDailyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'market_id' => Market::factory(),
            'brand_id' => fn (array $attributes): int => Market::query()->findOrFail($attributes['market_id'])->brand_id,
            'engine_key' => Engine::factory(),
            'domain' => 'directory.example.test',
            'date' => today(),
            'citations' => 1,
            'responses_with_domain' => 1,
        ];
    }
}
