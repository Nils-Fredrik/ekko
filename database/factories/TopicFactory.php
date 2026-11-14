<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Topic> */
class TopicFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->words(3, true);

        return [
            'brand_id' => Brand::factory(),
            'name' => $name,
            'cross_market_key' => Str::slug($name),
        ];
    }
}
