<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Competitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Competitor> */
class CompetitorFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'name' => $this->faker->company(),
            'is_active' => true,
        ];
    }
}
